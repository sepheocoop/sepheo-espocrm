/**

   NAME

   clean-directory-data.ok

   SYNOPSIS

   Reads in a CSV containing the NocoDb directory data on stdin.

   Cleans the data ready for import to EspoCRM, and writes it to stdout.

 */
import { parse } from 'csv-parse';
import { stringify } from 'csv-stringify';
import { transform } from 'stream-transform';
import { parsePhoneNumberWithError } from 'libphonenumber-js';
import * as fs from 'fs';

const dropLeadingBacktick = (v) => v.replace(/^\'/, '');

const drop000Timezone = (v) => v.replace(/[+]00:00$/, '');

const normalisePhone = (v) => {
    let v2;
    try {
        v2 = dropLeadingBacktick(v);
        v2 = v2.replace(/^1-/, '+1-');
        return parsePhoneNumberWithError(v2, 'GB')?.formatInternational();
    } catch (e) {
        console.error(`${v} / ${v2}: ${e}`);
        return undefined;
    }
};

//console.log(headers);
const transforms = {
    MatrixID: dropLeadingBacktick,
    Telephone: normalisePhone,
    JoinedAt: drop000Timezone,
};

const x = {};
let headers;
process.stdin
    .pipe(parse())
    .pipe(
        transform((row) => {
            if (!headers) {
                let ix = 0;
                headers = row;
                // Build a header -> index map
                for (const header of row) {
                    //console.log(header, ix);
                    x[header] = ix++;
                }
                return row;
            }
            return row.map((val, ix) => {
                const transform = transforms[headers[ix]];
                val = val?.trim();
                return transform ? transform(val) : val;
            });
        }),
    )
    .pipe(stringify())
    .pipe(process.stdout);
