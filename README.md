# Admissions for Moodle (Oksigenia Eguahe)

[![License: GPLv3](https://img.shields.io/badge/license-GPLv3+-blue.svg)](LICENSE)
![Moodle](https://img.shields.io/badge/Moodle-4.5%E2%80%935.3-orange)
![Status](https://img.shields.io/badge/status-alpha-red)
[![Sponsor](https://img.shields.io/badge/sponsor-Oksigenia-00d4ff)](https://oksigenia.com/en/open-source#sponsor)

**Oksigenia Eguahe** is the front door of a school, an academy or a college, inside its Moodle™ site or as a hosted
service. The families who ask for information or a place are received by people, and the team sees at a glance which
households are waiting for an answer, how many places each course still has, and how the campaign is going.
*Eguahe* comes from *eguahẽ porã*, "welcome" in Guarani.

[Leer en español](README.es.md)

## Status

**Alpha.** Not for production sites yet.

## What it does

- **The household is the unit**: its adults and the children who would study, each with the course they ask for and
  how far their way has gone (inquiry, visit, application, place offered, waiting list, enrolled or closed, with the
  reason). Each stage has one meaning, the same every year, so the numbers of one campaign compare with the next. A
  family found again by any of its phones or emails is never created twice.
- **Open days, visits and other events** (a party, a talk) with their capacity, or by turns, each with its own; a QR
  for the poster and a sign-up page the family fills in on its phone, in Spanish, English or Portuguese, with no
  account. At the door, the team ticks who came, and adds whoever arrives without signing up. Families already of the
  centre are marked and stay out of the numbers of the campaign.
- **The page of each family**, opened with a private link: their next visit, with the file for their calendar, what
  the centre keeps about them, and two buttons to download their data or erase it, whenever they want.
- **The inbox of the team**: who is waiting for an answer and since when, the longest wait first; the history of each
  household; answers, notes and the permissions each adult gave for each channel.
- **Offer and places**: for each course, those interested, those who visited, those enrolled and the places left, with
  the definition of each number on screen.
- **Who supervises** can see everything of their centre without answering nor changing anything.
- **Files as they come**: a spreadsheet with all its sheets, the export of the adverts, the answers of a form or a CSV.
  Eguahe sees what each sheet is (contacts of a campaign, new students, leaves, the list of a school year, totals,
  population) and what each column holds, shows it before importing, removes what is personal on the way in and turns
  emails, phones and codes into keys that join the same family or student across files. Nothing is refused.
- **The numbers that do not deceive**: a past campaign imported pseudonymised, by households from the first contact
  to the enrolment, by channel from both ends and with what each cost per new student; who came and went in each
  school year, with the balance and the reasons; the enrolment year by year. Each number with its definition.
- **Several centres in one site**: every row carries its centre, and each centre has its own team, colour, logo, area
  and texts about the data of its families.
- **Against spam**, without third parties nor cookies: [ALTCHA](https://altcha.org) (MIT), a small proof of work.
- **Privacy**: families are not Moodle users; they take and erase their data from their page. What the plugin keeps
  about the team goes through the Privacy API of Moodle.

- **The forecast**: for each entry course, the students to expect in the next years as a range, from the share of
  the children of its area the centre took before, with its places as the ceiling; and its reach in each municipality.
  The open data comes from the Canary Islands Statistics Institute (ISTAC) when the centre asks for it (the only call
  outside the site, with its source cited), or from a CSV of the centre anywhere else.

Example data for a demo: `php local/oksigeniaeguahe/cli/sample.php`.

## Requirements

Moodle 4.5 to 5.3. The public pages do not use the JavaScript of Moodle; they work on any recent phone.

## License

GNU GPL v3 or later. See [LICENSE](LICENSE). The bundled ALTCHA widget and library are MIT
([thirdpartylibs.xml](thirdpartylibs.xml)).

© 2026 Oksigenia SL — dev@oksigenia.cc

The word Moodle and associated Moodle logos are trademarks or registered trademarks of Moodle Pty Ltd or its
related affiliates. Oksigenia is not affiliated with Moodle HQ and is not a Moodle Certified Partner.
