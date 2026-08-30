const fs = require('fs');
let str = fs.readFileSync('C:\\laragon\\www\\Sistema_POS\\pos-frontend\\lib\\core\\providers\\local_terminal_provider.dart', 'utf8');
str = str.replace(/\ufffd/g, 'á');
fs.writeFileSync('C:\\laragon\\www\\Sistema_POS\\pos-frontend\\lib\\core\\providers\\local_terminal_provider.dart', str, 'utf8');

let str2 = fs.readFileSync('C:\\laragon\\www\\Sistema_POS\\pos-frontend\\lib\\main.dart', 'utf8');
str2 = str2.replace(/Conexi\ufffdn/g, 'Conexión').replace(/versi\ufffdn/g, 'versión').replace(/Actualizaci\ufffdn/g, 'Actualización').replace(/\ufffd/g, 'í');
fs.writeFileSync('C:\\laragon\\www\\Sistema_POS\\pos-frontend\\lib\\main.dart', str2, 'utf8');
