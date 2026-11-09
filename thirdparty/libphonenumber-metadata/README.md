# Calling codes of every region

`callingcodes.json` holds, for each of the 245 regions of libphonenumber (Apache-2.0), its calling code, its national
(trunk) prefix and its international prefix as a pattern. Eguahe reads it in `classes/local/phone.php`; the code knows no
country.

To make it again with a newer libphonenumber:

```
python3 -m venv /tmp/tel && /tmp/tel/bin/pip install phonenumbers
/tmp/tel/bin/python -I - callingcodes.json <<'PY'
import json, sys, phonenumbers
from phonenumbers import PhoneMetadata
out = {}
for region in sorted(phonenumbers.SUPPORTED_REGIONS):
    m = PhoneMetadata.metadata_for_region(region)
    if m and m.country_code:
        out[region] = {'code': str(m.country_code), 'national': m.national_prefix or '',
                       'international': m.international_prefix or ''}
json.dump({'source': 'libphonenumber ' + phonenumbers.__version__ + ' (Apache-2.0)', 'regions': out},
          open(sys.argv[1], 'w'), indent=1)
PY
```
