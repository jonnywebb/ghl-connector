<?php
/**
 * Network defaults for multisite.
 *
 * Network Admin > Settings > GHL Connector holds one Private Integration Token
 * and Location ID for every site. A site uses them unless it saves its own
 * values under Forms > Settings > GHL.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class GHL_Connector_Network {

    const PAGE  = 'ghl-connector-network';
    const NONCE = 'ghl_connector_network_save';
    // Stands in for the saved token so the password field shows dots. The
    // real token is never sent to the browser; posting this back keeps it.
    const MASK  = '__ghl_saved_token__';

    public static function init() {
        if ( ! is_multisite() ) return;
        add_action( 'network_admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'network_admin_edit_' . self::PAGE, array( __CLASS__, 'save' ) );
    }

    /** Site-option name for an add-on setting key (apiKey, locationId). */
    public static function option( $key ) {
        return 'ghl_connector_network_' . $key;
    }

    public static function menu() {
        add_submenu_page( 'settings.php', 'GHL Connector', 'GHL Connector', 'manage_network_options', self::PAGE, array( __CLASS__, 'render' ) );
    }

    public static function render() {
        if ( ! current_user_can( 'manage_network_options' ) ) return;

        $has_token = get_site_option( self::option( 'apiKey' ), '' ) !== '';
        $location  = get_site_option( self::option( 'locationId' ), '' );
        ?>
        <div class="wrap">
            <h1>GHL Connector</h1>
            <?php if ( isset( $_GET['updated'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p>Network settings saved.</p></div>
            <?php endif; ?>
            <p>These apply to every site in the network. A site can use a different token or location by entering its own under <strong>Forms &gt; Settings &gt; GHL</strong>; leaving those blank uses the values here.</p>
            <form method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=' . self::PAGE ) ); ?>">
                <?php wp_nonce_field( self::NONCE ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="ghl_api_key">Private Integration Token</label></th>
                        <td>
                            <input type="password" id="ghl_api_key" name="ghl_api_key" class="regular-text" autocomplete="new-password" value="<?php echo $has_token ? esc_attr( self::MASK ) : ''; ?>">
                            <p class="description">
                                <?php echo $has_token ? 'A token is saved. Leave it as is to keep it, or paste a new one to replace it.' : 'No token saved yet.'; ?>
                                Scopes: <code>contacts.write</code>, <code>contacts.readonly</code>, <code>locations/customFields.readonly</code>, <code>users.readonly</code>.
                            </p>
                            <?php if ( $has_token ) : ?>
                                <label><input type="checkbox" name="ghl_clear_api_key" value="1"> Remove the saved token</label>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ghl_location_id">Location ID</label></th>
                        <td><input type="text" id="ghl_location_id" name="ghl_location_id" class="regular-text" value="<?php echo esc_attr( $location ); ?>"></td>
                    </tr>
                </table>
                <?php submit_button( 'Save network settings' ); ?>
            </form>
        </div>
        <?php
    }

    public static function save() {
        if ( ! current_user_can( 'manage_network_options' ) ) wp_die( 'Not allowed.' );
        check_admin_referer( self::NONCE );

        if ( ! empty( $_POST['ghl_clear_api_key'] ) ) {
            delete_site_option( self::option( 'apiKey' ) );
        } else {
            $token = trim( sanitize_text_field( wp_unslash( $_POST['ghl_api_key'] ?? '' ) ) );
            if ( $token !== '' && $token !== self::MASK ) update_site_option( self::option( 'apiKey' ), $token );
        }
        update_site_option( self::option( 'locationId' ), trim( sanitize_text_field( wp_unslash( $_POST['ghl_location_id'] ?? '' ) ) ) );

        wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'updated' => 1 ), network_admin_url( 'settings.php' ) ) );
        exit;
    }
}
