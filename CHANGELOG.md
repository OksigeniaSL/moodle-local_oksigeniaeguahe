# Changelog

## 0.6.1 (2026-11-07)

- The backpacks hang from a wooden knob seen from the front, drawn in front of the handle, instead of a hook behind it.
- Example data: the open day at one place, with the address of the centre. The first layer about the data, shorter:
  how long the data is kept goes to the second.

## 0.6.0 (2026-11-07)

- **The pages of the families, redrawn** (DESIGN.md of the art director): their own pages, nothing of Moodle. The
  heading is the name the family uses at home, written by hand, with the child's backpack on its hook and the
  first-day label on its pocket (one per child; it writes itself while the name is typed). The visit in a band of the
  colour of the centre, with the calendar and how to get there; who will welcome them, with their face; what they told
  us and their data. The sign-up, the question before erasing, the messages and the page about the data in the same
  language. Schibsted Grotesk and Caveat served by the plugin (OFL), no third parties.
- **Any colour of a centre stays readable**: the server turns it into the variables of the page, for light and dark,
  with text at 4.5:1 on the wall and fills at 3:1 in dark (no color-mix(), it works in Firefox 98).
- **Who welcomes the families**: the centre chooses which people of its team families see, with their photo, what
  they do and a line of theirs, and who welcomes them at each open day or visit.
- **What families see of the centre**: its address, phone, email and a line about what a visit is like.
- **Families moving to the area**: «We are moving to the area» with where they come from, noted on the household.
- At least one way to write to the family is asked for, and only the ways they gave the means for are shown.
- Works without JavaScript: the rows waiting to be added show, and the turns, the chips and the number of people are
  real inputs.

## 0.5.0 (2026-11-07)

- **Files as they come**: xlsx, xls, ods and csv, every sheet. The header is found wherever it is (below a title,
  repeated to the side as adverts exports do), each column is recognised by its name in Spanish, English or Portuguese
  and by what it holds, and each sheet by what it is: contacts of a campaign (adverts, forms), new students, leaves,
  the list of a school year, totals or population. Before importing, the centre sees what stays, what becomes a key
  and what is removed, and can change the kind of each sheet. Nothing is refused: what is not understood stays out.
- **Pseudonymised on the way in**: names, notes, health data, passwords, addresses and documents are removed without
  being kept; of a date of birth, the year; emails, phones, student codes and children's names become a keyed hash
  (HMAC-SHA256 with a secret of the centre) that joins the same family or student across files, and several keys of
  one family become one. The uploaded file is deleted after the import.
- **Enrolled, found**: a family of a campaign counts as enrolled when a student of its family, or the child it named,
  appears for the first time in the lists of the centre within two years of its first contact. Families already at
  the centre (siblings, «son del cole») are counted apart, out of the way to the enrolment.
- **New students, joins and leaves without the whole list**: a year with only the new ones and the leaves shows them
  and their balance, without making up the start or the end. A student on two lists counts once. Where the new ones
  come from and where those who leave go.
- **One name per course**: «INF 1º A», «Infantil 3 años», «1º de Primaria», «Primaria 1º» or «infantil_3_años» are
  brought, by the age they start at, to the name of the offer of the centre, also in the data imported before.

## 0.4.0 (2026-11-07)

- **The forecast** («Forecast» tab): for each course the centre forecasts, its share of the children born in its area
  in the years it has, and the students it can expect in the next three school years as a range (lowest and highest
  share, and the median), with its places as the ceiling. Aggregate always, never of a family. Where births are not
  published yet, the children of that age from the population by age; where neither is, it says so.
- **The reach by municipality**: the students of the centre against the children of the ages of its courses who live
  in each municipality, to see where it can grow and where to aim adverts by area.
- **Open data of the area**: from the Canary Islands Statistics Institute (ISTAC), births by municipality and children
  by age, fetched only when the centre asks for it and then once a week, with its source cited; anywhere else, the
  centre uploads the same numbers as a CSV.
- The age at which each course starts, suggested from its name and set by the centre.

## 0.3.0 (2026-11-07)

- **What already happened, imported**: the contacts of a past campaign, the students of a school year and the totals
  of past years, in the import format, pseudonymised. A file with an email, a phone in a text or a name instead of a
  code is refused whole, with its lines. Imports can be removed.
- **The numbers** («Numbers» tab), each with its definition: how fast families get a first answer in Eguahe; a past
  campaign by households from the first contact to the enrolment, by channel from both ends (first and last contact),
  by campaign or form, by network for Meta, by month and by course; what each channel cost per new student, noted on
  the page; who started, joined and left in a school year, with the balance, the reasons and where the students live;
  and the students of each course year by year.
- **The name on the label**: the family can say the name used at home, which goes on the label of what it gets.

## 0.2.0 (2026-11-07)

- **Events by turns**: an event can have several turns, each with its start, how long it lasts and its capacity. The
  family chooses its turn on the same page (only the hours when all are on one day, with the places left), the QR and
  the poster are one, and its page, mail and calendar file carry its turn. At the door, the list goes by turn.
- **Another event**, beside open days and visits: a party, a talk, a fair. Who would study is optional, coming does not
  move anyone's way to «Visit», and families say whether they already have someone at the centre.
- **Families already of the centre** are marked, shown with a label and kept out of the numbers of the offer.
- **Only seeing**: a new capability, `local/oksigeniaeguahe:view`, for whoever supervises: the inbox, the households,
  the events and the numbers of their centres, without answering nor changing anything.
- Upgrade from 0.1: a table for the turns and two new fields, nothing lost.

## 0.1.2 (2026-11-07)

- Counts read well with one and with many: «Personas: 1», «Han venido: 1».

## 0.1.1 (2026-11-07)

- A daily task erases the households with no activity for the months their centre keeps data, as families read in
  the information about their data; households with a child enrolled stay.

## 0.1.0 (2026-11-07)

First alpha.

- Centres with their team, colour, logo, area, offer with places and the texts about the data of their families.
- Households with their adults and children, found again by phone or email; the way of each child, with stages of
  fixed meaning and the reason when it closes.
- Open days and visits with capacity, QR, poster and a public sign-up page in Spanish, English and Portuguese (Brazil
  and Portugal), with ALTCHA against spam; attendance at the door and families added there.
- The private page of each family: its next visit with its calendar file, its data to download or erase.
- The inbox of the team, the household page with its history and permissions, and the offer against its places.
- Privacy API for the team; example data (`cli/sample.php`).
