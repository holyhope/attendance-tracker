import { chromium }            from 'playwright';
import { execSync, spawn }      from 'child_process';
import { mkdirSync }            from 'fs';
import { resolve, dirname }     from 'path';
import { fileURLToPath }        from 'url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const dist = resolve(root, 'dist');
const out  = resolve(root, 'screenshots');

mkdirSync(out, { recursive: true });

// Sync public/ → dist/www/ so screenshots always reflect current source
execSync(`rsync -a --delete ${root}/public/ ${dist}/www/`, { stdio: 'inherit' });

// Prepare demo environment in dist/
console.log('Setting up demo environment…');
execSync(`php ${root}/tests/e2e/setup.php ${dist}`, { stdio: 'inherit' });

// Start PHP built-in server
const server = spawn('php', ['-S', '127.0.0.1:8765', '-t', `${dist}/www`], { stdio: 'pipe' });
process.on('exit', () => server.kill());

// Wait for server to accept connections
async function waitForServer(url, attempts = 20) {
    for (let i = 0; i < attempts; i++) {
        try { await fetch(url); return; } catch { /* not ready yet */ }
        await new Promise(r => setTimeout(r, 200));
    }
    throw new Error('PHP server did not start in time');
}

await waitForServer('http://127.0.0.1:8765/');

const browser = await chromium.launch();
const page    = await browser.newPage();
await page.setViewportSize({ width: 390, height: 844 }); // iPhone 14

try {
    console.log('Capturing pointage page…');
    await page.goto('http://127.0.0.1:8765/', { waitUntil: 'load' });
    await page.waitForTimeout(300);
    await page.screenshot({ path: `${out}/pointage.png`, fullPage: true });

    console.log('Capturing admin page…');
    await page.goto('http://127.0.0.1:8765/admin/', { waitUntil: 'load' });
    await page.waitForTimeout(300);
    await page.screenshot({ path: `${out}/admin.png`, fullPage: true });

    console.log(`Screenshots saved to ${out}`);
} finally {
    await browser.close();
    server.kill();
}
