# Record of processing activities: admissions

An entry for the record of processing activities of the centre, for its data protection officer to review and
complete. The centre fills in the brackets. It describes Oksigenia Eguahe 0.1: without artificial intelligence and
without messaging channels, which are added to this entry when the centre turns them on.

| Field | Content |
|---|---|
| Activity | Answering the families who ask for information or a place, visits and open days, and following the admission |
| Controller | [Legal name of the centre], [address], [email for exercising rights] |
| Data protection officer | [Name or service and email, if the centre has one] |
| Processor | [Whoever hosts and maintains the Moodle site of the centre], under a processing agreement |
| Purposes | Answering families; organising visits and open days, with their capacity and attendance; following each request until enrolment or its closing; and knowing, only in aggregate numbers, how the campaign is going. If the centre imports past campaigns, its joins and leaves or its enrolment to analyse them, they go in pseudonymised and only for the numbers: the file is read as it comes; on the way in, names, notes, health data, passwords, addresses and documents are removed; of the date of birth only the year stays; emails, phones, student codes and the child's name become a key encrypted with a secret of the centre (HMAC-SHA256), which only serves to recognise the same family or student in another file; and the file is not kept |
| Legal basis | The family's own request (pre-contractual steps), to answer it and organise the visit; consent, to write to them on each channel they tick (email, WhatsApp, phone), which they can withdraw |
| Data subjects | The adults of the families who contact the centre; the minors who would study there |
| Data | Of adults: first name, surname, phone, email, language, municipality (if given), how they heard of the centre (if given), what they write to the centre and their permissions by channel, with their date. Of minors: first name, the name used at home (if given) and the course asked for. No special categories are asked for |
| Automated decisions | None. No family is scored or ranked |
| Recipients | Nobody outside the centre, unless the law requires it. The processor, on behalf of the centre |
| International transfers | None [if hosting is in the EU] |
| Erasure | After [24] months without activity (set per centre), automatically every night. Families erase their data whenever they want from their page. If there is an enrolment, the data passes to the record of families of the centre |
| Security measures | Access only for the admissions team of the centre, by Moodle user and capability; each centre sees only its families; the private link of each family kept only as a hash (SHA-256) and renewable; public pages without trackers, third parties or indexing; anti-spam without third parties (ALTCHA); [encrypted backups of the site, as its hosting provides] |
| Rights | From the family's page: download and erase their data. In writing to [email]: access, rectification, restriction and objection. A complaint to the data protection authority |

## When this entry has to change

Before the centre turns on any of these, the data protection officer updates this entry and the information to
families (the first layer of the form and the page «How we treat your data»):

- **The artificial intelligence of the team** (drafts and summaries): add as processor the AI provider the centre sets
  up with its own key; the international transfers, if the provider processes the data outside the European Economic
  Area; and the impact assessment. What is sent to it goes without names, phones or emails.
- **WhatsApp or Telegram**: add Meta or Telegram as recipients of the messages, their international transfers and the
  permission by channel, which the family gives when signing up or writing.
- **The forms of Meta or Google ads, or of the website of the centre**: add each source and what each form asks for.
- **The people of the team who welcome the families** (from 0.6.0): their name, photo, what they do and a line of
  theirs appear on the pages of the families signed up. It is a processing of staff data, apart from this one: with the
  permission of each person to use their image, which they withdraw by leaving «Who welcomes the families».
- **Importing what already happened** (campaigns, joins and leaves, lists of students): add the sources the centre
  imports (its forms, its adverts, its office) and how long it keeps the imports, which are removed by hand.
