const fs = require('fs');
const path = require('path');

function walk(dir) {
    let results = [];
    const list = fs.readdirSync(dir);
    list.forEach(function(file) {
        file = path.join(dir, file);
        const stat = fs.statSync(file);
        if (stat && stat.isDirectory()) { 
            results = results.concat(walk(file));
        } else { 
            if (file.endsWith('.dart') || file.endsWith('.md')) {
                results.push(file);
            }
        }
    });
    return results;
}

const files = walk('C:\\laragon\\www\\Sistema_POS\\pos-frontend\\lib');

for (const file of files) {
    let str = fs.readFileSync(file, 'utf8');
    if (str.includes('\ufffd')) {
        console.log('Found replacement char in ' + file);
    }
}
