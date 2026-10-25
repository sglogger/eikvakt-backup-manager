<?php
/**
 * Settings, one tab per area.
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

namespace EikvaktBackupManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ekbm_tab  = Admin::current_tab();
$ekbm_tabs = [
	'general'    => __( 'General', 'eikvakt-backup-manager' ),
	'schedule'   => __( 'Schedule', 'eikvakt-backup-manager' ),
	'storage'    => __( 'Storage', 'eikvakt-backup-manager' ),
	'files'      => __( 'Files', 'eikvakt-backup-manager' ),
	'database'   => __( 'Database', 'eikvakt-backup-manager' ),
	'encryption' => __( 'Encryption', 'eikvakt-backup-manager' ),
	'retention'  => __( 'Retention', 'eikvakt-backup-manager' ),
	'security'   => __( 'Security', 'eikvakt-backup-manager' ),
];
?>
<div class="wrap ekbm-wrap">
	<?php Admin::header( __( 'Settings', 'eikvakt-backup-manager' ) ); ?>

	<h2 class="nav-tab-wrapper">
		<?php foreach ( $ekbm_tabs as $ekbm_slug => $ekbm_label ) : ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Admin::MENU_SETTINGS . '&tab=' . $ekbm_slug ) ); ?>" class="nav-tab <?php echo $ekbm_slug === $ekbm_tab ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $ekbm_label ); ?></a>
		<?php endforeach; ?>
	</h2>

	<?php require EKBM_DIR . 'admin/views/settings/tab-' . $ekbm_tab . '.php'; ?>
</div>
