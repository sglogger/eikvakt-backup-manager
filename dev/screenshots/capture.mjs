/**
 * Capture the documentation screenshots of every plugin screen, in English
 * and German.
 *
 * Run through ./run.sh, which seeds demo data with WP-CLI and starts this
 * script inside the network namespace of the WordPress container. The site
 * believes it lives at http://localhost:9190, so a tiny TCP proxy forwards
 * that port to the container's Apache on port 80.
 *
 * Environment: LOCALE (en|de), RESTORE_ID, RUN_ID, WP_PORT.
 */
import { chromium } from 'playwright';
import { createServer, connect } from 'node:net';
import { mkdir, copyFile } from 'node:fs/promises';
import { resolve } from 'node:path';

const root = resolve( import.meta.dirname, '../..' );
const port = Number( process.env.WP_PORT || 9190 );
const locale = process.env.LOCALE || 'en';
const base = `http://localhost:${ port }`;
const out = `${ root }/screenshots/${ locale }`;

const proxy = createServer( ( client ) => {
	const upstream = connect( 80, '127.0.0.1' );
	client.pipe( upstream ).pipe( client );
	upstream.on( 'error', () => client.destroy() );
	client.on( 'error', () => upstream.destroy() );
} );
await new Promise( ( done ) => proxy.listen( port, '127.0.0.1', done ) );

await mkdir( out, { recursive: true } );

const browser = await chromium.launch();
const page = await browser.newPage( { viewport: { width: 1360, height: 900 }, deviceScaleFactor: 1 } );

await page.goto( `${ base }/wp-login.php` );
await page.fill( '#user_login', 'admin' );
await page.fill( '#user_pass', 'admin123' );
await Promise.all( [ page.waitForNavigation(), page.click( '#wp-submit' ) ] );

const admin = ( query ) => `${ base }/wp-admin/admin.php?${ query }`;

const shots = [
	[ '01-dashboard', admin( 'page=eikvakt-backup-manager' ) ],
	[ '02-backups', admin( 'page=eikvakt-backup-manager-backups' ) ],
	[ '03-restore', admin( `page=eikvakt-backup-manager-restore&backup=${ process.env.RESTORE_ID || '' }` ) ],
	[ '04-migration', admin( 'page=eikvakt-backup-manager-migration' ) ],
	[ '05-reports', admin( 'page=eikvakt-backup-manager-reports' ) ],
	[ '06-report-detail', admin( `page=eikvakt-backup-manager-reports&run=${ process.env.RUN_ID || 1 }` ) ],
	[ '07-settings-storage', admin( 'page=eikvakt-backup-manager-settings&tab=storage' ) ],
	[ '08-settings-storage-dropbox', admin( 'page=eikvakt-backup-manager-settings&tab=storage&add=dropbox' ) ],
	[ '09-settings-schedule', admin( 'page=eikvakt-backup-manager-settings&tab=schedule' ) ],
	[ '10-settings-retention', admin( 'page=eikvakt-backup-manager-settings&tab=retention' ) ],
	[ '11-settings-security', admin( 'page=eikvakt-backup-manager-settings&tab=security' ) ],
	[ '12-settings-general', admin( 'page=eikvakt-backup-manager-settings&tab=general' ) ],
	[ '13-settings-database', admin( 'page=eikvakt-backup-manager-settings&tab=database' ) ],
	[ '15-settings-files', admin( 'page=eikvakt-backup-manager-settings&tab=files' ) ],
	[ '14-settings-encryption', admin( 'page=eikvakt-backup-manager-settings&tab=encryption' ) ],
];

for ( const [ name, url ] of shots ) {
	await page.goto( url, { waitUntil: 'networkidle' } );
	if ( name.endsWith( 'files' ) ) {
		await page.waitForSelector( '#ekbm-tree input[type=checkbox]' );
		await page.waitForTimeout( 1500 );
	}
	// Hide notices that belong to WordPress, not to this plugin.
	await page.addStyleTag( { content: '.update-nag, .notice:not(.inline):not(.is-dismissible), #wpfooter { display:none !important }' } );
	await page.screenshot( { path: `${ out }/${ name }.png`, fullPage: true } );
	console.log( `${ locale }/${ name }.png` );
}

// The WordPress.org directory shows English screenshots, numbered.
if ( 'en' === locale ) {
	const listed = [ '01-dashboard', '02-backups', '03-restore', '04-migration', '05-reports', '07-settings-storage', '09-settings-schedule', '10-settings-retention', '11-settings-security' ];
	await mkdir( `${ root }/.wordpress-org`, { recursive: true } );
	for ( const [ i, name ] of listed.entries() ) {
		await copyFile( `${ out }/${ name }.png`, `${ root }/.wordpress-org/screenshot-${ i + 1 }.png` );
	}
}

await browser.close();
proxy.close();
