import fs from 'fs-extra';

fs.copySync('./src/files/custom', './custom');
console.log('Copied src/files/custom → ./custom');
fs.copySync('./dev_files/Custom', './custom/Espo/Custom');
console.log('Copied dev_files/Custom → ./custom/Espo/Custom');
