<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_oksigeniaeguahe\local;

/**
 * What the AI of the centre adds to a report: the themes of what the families wrote in the period, counted and said in
 * a sentence each, a short reading of the period, and advice of its own beside the fixed rules. It gets only the
 * figures added up and the texts of the families without mails, phones or addresses, and it is asked never to name
 * anyone nor to mark a family.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_ai {
    /** @var int The texts of families sent, at most: the latest. */
    const TEXTS = 150;

    /** @var int The characters of each text sent, at most. */
    const LENGTH = 600;

    /** @var int The themes kept, at most. */
    const THEMES = 6;

    /** @var int The advice of the AI kept, at most. */
    const ADVICE = 3;

    /** @var string[] The languages of the centre, as the AI is told to write. */
    const NAMES = ['es' => 'Spanish (Spain)', 'en' => 'English', 'pt' => 'Portuguese (Portugal)', 'pt_br' => 'Portuguese (Brazil)',
        'ar' => 'Arabic'];

    /**
     * What the families wrote in a period: their messages, what they asked from their page and what they told after a
     * visit in words, without mails, phones or addresses of the web.
     *
     * @param int $centreid
     * @param int $from
     * @param int $to
     * @return string[]
     */
    public static function texts(int $centreid, int $from, int $to): array {
        global $DB;
        $out = [];
        $said = $DB->get_records_select(
            households::CONTACTS,
            "centreid = ? AND direction = 'in' AND timecreated >= ? AND timecreated < ?",
            [$centreid, $from, $to],
            'timecreated DESC',
            'id, body',
            0,
            self::TEXTS
        );
        foreach ($said as $row) {
            $out[] = (string) $row->body;
        }
        $told = $DB->get_records_sql("SELECT s.id, s.value
                                        FROM {" . surveys::T . "} s
                                        JOIN {" . forms::FIELDS . "} f ON f.id = s.fieldid
                                       WHERE s.centreid = ? AND f.type IN ('text', 'longtext')
                                         AND s.timemodified >= ? AND s.timemodified < ?
                                    ORDER BY s.timemodified DESC", [$centreid, $from, $to], 0, self::TEXTS);
        foreach ($told as $row) {
            $out[] = (string) $row->value;
        }
        $clean = [];
        foreach ($out as $text) {
            $text = self::clean($text);
            if ($text !== '') {
                $clean[] = $text;
            }
        }
        return array_slice($clean, 0, self::TEXTS);
    }

    /**
     * A text of a family without what could reach a person: mails, phones and addresses of the web; cut short.
     *
     * @param string $text
     * @return string
     */
    public static function clean(string $text): string {
        $text = html_to_text($text, 0, false);
        $text = preg_replace('/[^\s@]+@[^\s@]+\.[^\s@]+/u', '[…]', $text);
        $text = preg_replace('#\b(?:https?://|www\.)\S+#iu', '[…]', $text);
        $text = preg_replace('/\+?\d[\d\s().-]{6,}\d/u', '[…]', $text);
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        return \core_text::substr($text, 0, self::LENGTH);
    }

    /**
     * What is asked: the figures of the report added up, the advice the rules already gave, and the texts.
     *
     * @param \stdClass $centre
     * @param array $data
     * @param string[] $texts
     * @return string
     */
    public static function prompt(\stdClass $centre, array $data, array $texts): string {
        $lang = self::NAMES[$centre->lang ?? ''] ?? 'Spanish (Spain)';
        $figures = [];
        foreach ($data['figures'] ?? [] as $figure) {
            $figures[] = ['what' => $figure['label'], 'now' => $figure['value'], 'before' => $figure['before'] ?: null,
                'a year ago' => $figure['ago'] ?: null];
        }
        $facts = [
            'report' => ($data['kind'] ?? '') === 'cold' ? 'a whole school year, to prepare the next one' :
                'the last seven days, to correct on the way',
            'period' => ($data['period'] ?? '') . (!empty($data['sofar']) ? ' (under way, so far: every comparison is with the ' .
                'same point of the period before, and the months still to come have no families yet)' : ''),
            'sentence' => $data['sentence'] ?? '',
            'figures' => $figures,
            'first answer (half the families within)' => $data['response'] ?: null,
            'way to enrolment' => array_map(
                fn($s) => ['stop' => $s['name'], 'families' => $s['n'],
                'campaign before at the same height' => $s['before'] === false ? null : $s['before']],
                $data['funnel']['steps'] ?? []
            ),
            'pace' => array_map(fn($r) => ['when' => $r['name'] ?? $r['label'],
                'families' => $r['n'] === false ? 'still to come' : $r['n'],
                'a year before' => $r['before'] === false ? null : $r['before']], $data['pace']['rows'] ?? []),
            // Twelve months, not the period: said so, or it is read as the week.
            'channels, families in the last twelve months (not the period) and in the twelve before' => $data['channels'] ?
                array_map(fn($v) => ['voice' => $v['name'], 'rows' => array_map(fn($r) => ['channel' => $r['name'],
                'last twelve months' => $r['n'], 'twelve months before' => $r['before']], $v['rows'])], $data['channels']) :
                null,
            'places of the school year that is being filled' => array_map(fn($p) => ['course' => $p['name'],
                'children with a place or enrolled' => $p['taken'], 'places' => $p['places'], 'may still come, low' => $p['low'],
                'may still come, high' => $p['high']], $data['places'] ?: []),
            'acts' => $data['acts']['rows'] ?? null,
            'how they heard of the centre' => $data['voices']['heard'] ?? null,
            'what the adults like doing' => $data['voices']['likes'] ?? null,
            'why children left' => $data['gone']['reasons'] ?? null,
            'forecast' => $data['forecast'] ?: null,
            'where it can grow' => $data['grow'] ?: null,
            'advice already given' => array_column($data['advice'] ?? [], 'text'),
        ];
        $facts = array_filter($facts, fn($v) => $v !== null && $v !== [] && $v !== '');
        $numbered = [];
        foreach (array_values($texts) as $i => $text) {
            $numbered[] = ($i + 1) . '. ' . $text;
        }
        return "You help the admissions team of a school or educational centre read its report. The families are " .
            "received by people; you work behind, for the team.\n\n" .
            "Rules, all of them:\n" .
            "- Write in $lang, plainly, as a colleague would. No jargon, no marketing words." .
            (str_starts_with($lang, 'Spanish') ? ' Never «vosotros»: write impersonally or with «ustedes».' : '') . "\n" .
            "- Never name a person, never quote a text in a way that identifies a family, never mark, score or rank a " .
            "family, never guess who will enrol. Speak only of the families together.\n" .
            "- Base everything on the figures and texts below; do not invent numbers. If something is not there, leave it " .
            "out.\n" .
            "- Advice must be concrete, something the team can do this week (hot) or prepare for next year (cold), not " .
            "already in «advice already given», and each with its why in the figures.\n\n" .
            "Answer only with JSON, this shape:\n" .
            '{"reading": "two or three sentences: what matters most in this period", "themes": [{"theme": "a few words", ' .
            '"texts": number of texts that speak of it, "sentence": "what the families say of it, together"}], "advice": ' .
            '[{"text": "what to do", "why": "the figures that ask for it"}]}' . "\n" .
            'Up to ' . self::THEMES . ' themes, the most said first; up to ' . self::ADVICE . " pieces of advice. Without " .
            "texts, themes is an empty list.\n\n" .
            "The report:\n" . json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n" .
            'What the families wrote in the period (' . count($numbered) . "):\n" . ($numbered ? implode("\n", $numbered) :
            '(nothing)') . "\n";
    }

    /**
     * What the AI said, kept only as it should be: a reading, the themes with how many texts and a sentence, the advice
     * with its why; each cut to its size.
     *
     * @param string $answer
     * @param int $texts How many texts it was given: no theme can count more.
     * @return array|null
     */
    public static function parse(string $answer, int $texts): ?array {
        $data = ai::json($answer);
        if (!$data) {
            return null;
        }
        $cut = fn($v, int $n) => \core_text::substr(trim(clean_param((string) (is_scalar($v) ? $v : ''), PARAM_TEXT)), 0, $n);
        $themes = [];
        foreach (array_slice(is_array($data['themes'] ?? null) ? $data['themes'] : [], 0, self::THEMES) as $theme) {
            $name = $cut($theme['theme'] ?? '', 80);
            if ($name !== '' && $texts) {
                $themes[] = ['theme' => $name, 'texts' => max(1, min($texts, (int) ($theme['texts'] ?? 1))),
                    'sentence' => $cut($theme['sentence'] ?? '', 400)];
            }
        }
        usort($themes, fn($x, $y) => $y['texts'] <=> $x['texts']);
        $advice = [];
        foreach (array_slice(is_array($data['advice'] ?? null) ? $data['advice'] : [], 0, self::ADVICE) as $tip) {
            $text = $cut($tip['text'] ?? '', 300);
            if ($text !== '') {
                $advice[] = ['text' => $text, 'why' => $cut($tip['why'] ?? '', 400)];
            }
        }
        $reading = $cut($data['reading'] ?? '', 900);
        return $reading !== '' || $themes || $advice ? ['reading' => $reading, 'themes' => $themes, 'texts' => $texts,
            'advice' => $advice] : null;
    }

    /**
     * Asks the AI of the centre for a report and keeps what it says, or that it could not.
     *
     * @param int $reportid
     * @param int $userid Who asked; 0, the site.
     */
    public static function run(int $reportid, int $userid): void {
        global $DB;
        $record = $DB->get_record(reports::T, ['id' => $reportid]);
        $centre = $record ? centres::get((int) $record->centreid) : null;
        if (!$record || !$centre) {
            return;
        }
        $report = reports::get($reportid, (int) $centre->id);
        $texts = self::texts((int) $centre->id, (int) $report->periodfrom, (int) $report->periodto);
        try {
            $answer = ai::ask($centre, self::prompt($centre, $report->data, $texts), $userid ?: (int) get_admin()->id);
            $said = self::parse($answer, count($texts));
            reports::set_ai($reportid, $said, $said ? '' : 'unreadable');
        } catch (\moodle_exception $e) {
            reports::set_ai($reportid, null, $e->getMessage());
        }
    }
}
