import fs from "fs-extra";

fs.copySync("./src/files/custom", "./custom");
console.log("Copied src/files/custom → ./custom");

