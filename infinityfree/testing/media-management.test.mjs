import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { resolve } from 'node:path';

const root = resolve(new URL('.', import.meta.url).pathname, '..');
const [app, adminService, references, api, schema] = await Promise.all([
  readFile(resolve(root, 'assets/js/app.js'), 'utf8'),
  readFile(resolve(root, 'includes/AdminService.php'), 'utf8'),
  readFile(resolve(root, 'includes/ReferenceService.php'), 'utf8'),
  readFile(resolve(root, 'api/v1/index.php'), 'utf8'),
  readFile(resolve(root, 'database/schema.sql'), 'utf8'),
]);

assert.match(schema, /interior_image_path/);
assert.match(schema, /exterior_image_path/);
assert.match(adminService, /uploadCompanyMedia/);
assert.match(adminService, /uploadBusMedia/);
assert.match(adminService, /move_uploaded_file/);
assert.match(adminService, /5 \* 1024 \* 1024/);
assert.match(api, /admin\/companies\/\(\\d\+\)\/media/);
assert.match(api, /admin\/buses\/\(\\d\+\)\/media/);
assert.match(references, /co\.logo_path/);
assert.match(references, /b\.interior_image_path/);
assert.match(app, /uploadMedia/);
assert.match(app, /name="company_logo"/);
assert.match(app, /name="company_cover"/);
assert.match(app, /data-bus-media-upload/);
assert.match(app, /trip-media/);

console.log('اختبار إدارة وسائط الشركة والباص: ناجح');
