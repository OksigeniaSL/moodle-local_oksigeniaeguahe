# Changelog

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
