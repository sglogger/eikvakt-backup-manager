/**
 * Browser smoke test of the main click paths: start a backup from the
 * dashboard and follow the live progress to the end, then set the plugin
 * password, get locked out, and unlock again.
 *
 * Run through ./run.sh smoke (same network setup as capture.mjs).
 */
import { chromium } from 'playwright';
import { createServer, connect } from 'node:net';
import assert from 'node:assert/strict';

const port = Number( process.env.WP_PORT || 9190 );
const base = `http://localhost:${ port }`;

const proxy = createServer( ( c ) => {
	const u = connect( 80, '127.0.0.1' );
	c.pipe( u ).pipe( c );
	u.on( 'error', () => c.destroy() );
	c.on( 'error', () => u.destroy() );
} );
await new Promise( ( r ) => proxy.listen( port, '127.0.0.1', r ) );

const browser = await chromium.launch();
const context = await browser.newContext();
const page = await context.newPage();
page.on( 'dialog', ( d ) => d.accept() );

await page.goto( `${ base }/wp-login.php` );
await page.fill( '#user_login', 'admin' );
await page.fill( '#user_pass', 'admin123' );
await Promise.all( [ page.waitForNavigation(), page.click( '#wp-submit' ) ] );

// 1. Backup now (database only) with live progress.
await page.goto( `${ base }/wp-admin/admin.php?page=eikvakt-backup-manager` );
for ( const box of await page.locator( '.ekbm-components input[type=checkbox]' ).all() ) {
	if ( 'database' !== await box.getAttribute( 'value' ) ) {
		await box.uncheck();
	}
}
await Promise.all( [ page.waitForNavigation(), page.click( 'text=Start backup' ) ] );
// A small job may already be finished by the time the page has loaded.
assert.ok( await page.locator( '#ekbm-progress:not([hidden]), .ekbm-wrap .notice.inline' ).count(), 'progress or outcome shown' );
// The page reloads itself when the job is done and shows its outcome.
await page.waitForSelector( '.ekbm-wrap .notice.inline', { timeout: 120000 } );
const outcome = ( await page.locator( '.ekbm-wrap .notice.inline' ).innerText() ).trim();
assert.ok( /Success|warnings/.test( outcome ), outcome );
console.log( 'backup via dashboard: ok —', outcome );

// 2. Keep mark: a kept backup cannot be deleted until the mark is removed.
await page.goto( `${ base }/wp-admin/admin.php?page=eikvakt-backup-manager-backups&type=kept` );
const keptRow = page.locator( 'tbody tr', { has: page.locator( 'text=Unkeep' ) } ).first();
assert.ok( await keptRow.count(), 'a kept backup is listed under the Kept filter' );
await Promise.all( [ page.waitForNavigation(), keptRow.locator( '.ekbm-confirm-delete button' ).click() ] );
assert.ok( ( await page.content() ).includes( 'marked as keep' ), 'delete of a kept backup refused' );
console.log( 'keep blocks delete: ok' );

await page.goto( `${ base }/wp-admin/admin.php?page=eikvakt-backup-manager-backups` );
const freeRow = page.locator( 'tbody tr', { has: page.locator( 'button:text-is(" Keep")' ) } ).first();
const id      = await freeRow.locator( 'input[name=backup_id]' ).first().getAttribute( 'value' );
await Promise.all( [ page.waitForNavigation(), freeRow.locator( 'button:has-text("Keep")' ).click() ] );
assert.ok( ( await page.content() ).includes( 'Marked as keep' ), 'keep via button' );
await Promise.all( [ page.waitForNavigation(), page.locator( `tr:has(input[value="${ id }"]) button:has-text("Unkeep")` ).first().click() ] );
assert.ok( ( await page.content() ).includes( 'Keep mark removed' ), 'unkeep via button' );
console.log( 'keep / unkeep buttons: ok' );

// 3. Folder tree: saved rules are revealed; a click creates a rule that is saved.
await page.goto( `${ base }/wp-admin/admin.php?page=eikvakt-backup-manager-settings&tab=files` );
await page.waitForSelector( 'input[data-path="content/uploads/2026/10"]', { timeout: 30000 } );
assert.equal( await page.isChecked( 'input[data-path="content/uploads/2026/10"]' ), true, 'included subfolder checked' );
assert.equal( await page.isChecked( 'input[data-path="content/uploads/2026"]' ), false, 'excluded parent unchecked' );
await page.uncheck( 'input[data-path="content/themes"]' );
assert.ok( ( await page.locator( '#ekbm-rule-list' ).innerText() ).includes( 'content/themes' ), 'rule listed' );
await Promise.all( [ page.waitForNavigation(), page.click( '.ekbm-files-form #submit' ) ] );
await page.waitForSelector( 'input[data-path="content/themes"]' );
assert.equal( await page.isChecked( 'input[data-path="content/themes"]' ), false, 'rule saved' );
await page.check( 'input[data-path="content/themes"]' );
await Promise.all( [ page.waitForNavigation(), page.click( '.ekbm-files-form #submit' ) ] );
await page.waitForSelector( 'input[data-path="content/themes"]' );
assert.equal( await page.isChecked( 'input[data-path="content/themes"]' ), true, 'rule removed again' );
console.log( 'folder tree: ok' );

// 4. Password gate.
await page.goto( `${ base }/wp-admin/admin.php?page=eikvakt-backup-manager-settings&tab=security` );
await page.fill( 'input[name=new_password]', 'gate-password-123' );
await page.fill( 'input[name=new_password_confirm]', 'gate-password-123' );
await Promise.all( [ page.waitForNavigation(), page.click( 'form:has(input[name=new_password]) #submit' ) ] );
assert.ok( ( await page.content() ).includes( 'password is set' ), 'password saved' );

await context.clearCookies( { name: 'ekbm_unlock' } );
await page.goto( `${ base }/wp-admin/admin.php?page=eikvakt-backup-manager-backups` );
assert.ok( await page.locator( '#ekbm_password' ).count(), 'locked screen shown' );
assert.equal( await page.locator( '.ekbm-downloads' ).count(), 0, 'no downloads while locked' );

await page.fill( '#ekbm_password', 'wrong' );
await Promise.all( [ page.waitForNavigation(), page.click( '#submit' ) ] );
assert.ok( ( await page.content() ).includes( 'Wrong password' ), 'wrong password rejected' );

await page.fill( '#ekbm_password', 'gate-password-123' );
await Promise.all( [ page.waitForNavigation(), page.click( '#submit' ) ] );
assert.ok( await page.locator( '.ekbm-downloads' ).count() > 0, 'unlocked' );
console.log( 'password gate: ok' );

// Remove the password again.
await page.goto( `${ base }/wp-admin/admin.php?page=eikvakt-backup-manager-settings&tab=security` );
await page.fill( 'input[name=current_password]', 'gate-password-123' );
await page.check( 'input[name=disable_password]' );
await Promise.all( [ page.waitForNavigation(), page.click( 'form:has(input[name=new_password]) #submit' ) ] );
assert.ok( ( await page.content() ).includes( 'password has been removed' ) );
console.log( 'password removed: ok' );

await browser.close();
proxy.close();
