/**
 * Render the plugin icon (SVG master) to the PNG sizes the plugin and the
 * WordPress.org directory need, and the directory banners.
 *
 * Run through ./run.sh, which starts the Playwright image.
 */
import { chromium } from 'playwright';
import { readFile, mkdir } from 'node:fs/promises';
import { resolve } from 'node:path';

const root = resolve( import.meta.dirname, '../..' );
const svg = await readFile( `${ root }/assets/logos/eikvakt-backup-manager-icon.svg`, 'utf8' );
const svgData = 'data:image/svg+xml;base64,' + Buffer.from( svg ).toString( 'base64' );

await mkdir( `${ root }/.wordpress-org`, { recursive: true } );

const browser = await chromium.launch();
const page = await browser.newPage();

const icon = async ( size, file ) => {
	await page.setViewportSize( { width: size, height: size } );
	await page.setContent( `<html><body style="margin:0;background:transparent"><img src="${ svgData }" width="${ size }" height="${ size }"></body></html>` );
	await page.screenshot( { path: file, omitBackground: true } );
};

await icon( 1024, `${ root }/assets/logos/eikvakt-backup-manager-icon-1024x1024.png` );
await icon( 256, `${ root }/assets/logos/eikvakt-backup-manager-icon-256x256.png` );
// 64×64: the small app icon of the Dropbox App Console (branding).
await icon( 64, `${ root }/assets/logos/eikvakt-backup-manager-icon-64x64.png` );
await icon( 256, `${ root }/.wordpress-org/icon-256x256.png` );
await icon( 128, `${ root }/.wordpress-org/icon-128x128.png` );

// Banners: the icon on the same navy gradient, with name and claim.
const banner = async ( width, height, file ) => {
	const scale = width / 772;
	await page.setViewportSize( { width, height } );
	await page.setContent( `<html><body style="margin:0">
		<div style="width:${ width }px;height:${ height }px;display:flex;align-items:center;gap:${ 28 * scale }px;padding:0 ${ 48 * scale }px;box-sizing:border-box;
			background:radial-gradient(circle at 85% 20%, #17346B 0, transparent 45%), linear-gradient(135deg,#0B1739,#071022);
			font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#D8F7FF">
			<img src="${ svgData }" style="width:${ 170 * scale }px;height:${ 170 * scale }px">
			<div>
				<div style="font-size:${ 44 * scale }px;font-weight:700;letter-spacing:-0.5px">Eikvakt Backup Manager</div>
				<div style="font-size:${ 20 * scale }px;margin-top:${ 10 * scale }px;color:#67F4D1">Incremental backups · One-click restore &amp; migration</div>
				<div style="font-size:${ 16 * scale }px;margin-top:${ 8 * scale }px;color:#8fb3d9">Dropbox · OneDrive · SFTP · FTP · Encrypted · 100% free</div>
			</div>
		</div></body></html>` );
	await page.screenshot( { path: file } );
};

await banner( 772, 250, `${ root }/.wordpress-org/banner-772x250.png` );
await banner( 1544, 500, `${ root }/.wordpress-org/banner-1544x500.png` );

await browser.close();
console.log( 'Icons and banners rendered.' );
