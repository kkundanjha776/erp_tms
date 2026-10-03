import fs from 'node:fs/promises';
import { FileBlob, SpreadsheetFile } from '@oai/artifact-tool';

const input = await FileBlob.load('C:/Users/Sanjoy Mukherjee/Downloads/adison ptl register 2026-27.xlsx');
const workbook = await SpreadsheetFile.importXlsx(input);
const summary = await workbook.inspect({ kind: 'workbook,sheet,table', maxChars: 8000, tableMaxRows: 15, tableMaxCols: 25 });
console.log(summary.ndjson);
for (const sheet of workbook.worksheets.items) {
  const png = await workbook.render({ sheetName: sheet.name, autoCrop: 'all', scale: 1, format: 'png' });
  await fs.writeFile(`C:/xampp/htdocs/erp_tms/reference-${sheet.name.replace(/[^a-z0-9]/gi, '_')}.png`, new Uint8Array(await png.arrayBuffer()));
}
