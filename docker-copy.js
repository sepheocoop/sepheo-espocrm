import fs from 'fs-extra';

// We emulate the repo layout in the example module here:
// https://github.com/espocrm/ext-real-estate/tree/master/src/files

// Copy src/files/custom/ -> ./custom/server ; mounted to /var/www/html/custom
fs.copySync('./src/files/custom', './custom/server');
console.log('Copied src/files/custom → ./custom/server');

// Copy src/files/client/custom/ -> ./custom/client ; mounted to /var/www/html/client/custom
fs.copySync('./src/files/client/custom', './custom/client');
console.log('Copied src/files/custom → ./custom');

// Copy ./dev_files/Custom -> ./custom/Espo/Custom ; these are supplemental resources for testing.
// When using the module on a real server, these will need to be created appropriately for
// that instance.
fs.copySync('./dev_files/Custom', './custom/Espo/Custom');
console.log('Copied dev_files/Custom → ./custom/Espo/Custom');
