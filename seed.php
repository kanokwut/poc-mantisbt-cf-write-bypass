<?php
/**
 * Seeds the lab through MantisBT's own core APIs, run from the CLI:
 *   - a public project "PoC Project" with a category and one issue
 *   - a custom field "approval_status" that everyone can read (VIEWER)
 *     but only MANAGER and above may write, linked to the project
 *   - a low-privilege user "reporter1" (REPORTER, the default level
 *     for a self-registered account)
 * setup.sh copies it into the web root, runs it once, and deletes it.
 */

# ---- bootstrap MantisBT core (CLI mode) --------------------------------------
$g_bypass_headers = 1;
define( 'PLUGINS_DISABLED', true );
require_once __DIR__ . '/core.php';

# Establish an admin context: several core APIs (antispam_check on bug create,
# access checks) require a current user. Without this a CLI seed hits
# access_denied() -> print_header_redirect() and exits silently.
auth_attempt_script_login( 'administrator' );
fwrite( STDERR, "SEED: admin logged in, cur=" . auth_get_current_user_id() . "\n" );

# Access level constants (VIEWER/REPORTER/... come from constant_inc.php)
$t_rw = MANAGER;   # 70, the write threshold
$t_r  = VIEWER;    # 10, so the attacker can read the value back

function say( $k, $v ) { echo str_pad( $k, 16 ) . ": $v\n"; }

# ---- project -----------------------------------------------------------------
$t_project_name = 'PoC Project';
$t_project_id = project_get_id_by_name( $t_project_name );
if( $t_project_id === 0 ) {
	$t_project_id = project_create(
		$t_project_name, 'Lab project',
		/* status */ 10, VS_PUBLIC, '', /* enabled */ true, /* inherit */ true
	);
}

# ---- category ----------------------------------------------------------------
$t_category_id = category_get_id_by_name( 'General', $t_project_id, false );
if( $t_category_id === false ) {
	$t_category_id = category_add( $t_project_id, 'General' );
}

# ---- custom field: readable by all, writable only by MANAGER+ ----------------
$t_cf_name = 'approval_status';
$t_cf_id = custom_field_get_id_from_name( $t_cf_name );
if( $t_cf_id === false ) {
	$t_cf_id = custom_field_create( $t_cf_name );
}
# Set thresholds + type directly (robust, avoids partial def arrays).
db_query(
	'UPDATE {custom_field} SET access_level_r = ' . db_param()
		. ', access_level_rw = ' . db_param()
		. ', type = ' . db_param() . ' WHERE id = ' . db_param(),
	array( $t_r, $t_rw, CUSTOM_FIELD_TYPE_STRING, $t_cf_id )
);
config_flush_cache();
if( !custom_field_is_linked( $t_cf_id, $t_project_id ) ) {
	custom_field_link( $t_cf_id, $t_project_id );
}

# ---- target bug --------------------------------------------------------------
$t_admin_id = user_get_id_by_name( 'administrator' );
if( $t_admin_id === false ) { $t_admin_id = 1; }

$t_bug = new BugData;
$t_bug->project_id       = $t_project_id;
$t_bug->reporter_id      = $t_admin_id;
$t_bug->handler_id       = 0;
$t_bug->category_id      = $t_category_id;
$t_bug->summary          = 'PoC target bug';
$t_bug->description      = 'Lab issue.';
$t_bug->view_state       = VS_PUBLIC;
$t_bug->severity         = MINOR;
$t_bug->priority         = NORMAL;
$t_bug->reproducibility  = 70; # have not tried
$t_bug->eta              = 10;
$t_bug->projection       = 10;
$t_bug_id = $t_bug->create();
# seed a benign initial value so the change is unmistakable
custom_field_set_value( $t_cf_id, $t_bug_id, 'PENDING' );

# ---- low-privilege attacker user --------------------------------------------
$t_user = 'reporter1';
$t_pass = 'Passw0rd!reporter';
if( user_get_id_by_name( $t_user ) === false ) {
	user_create( $t_user, $t_pass, 'reporter1@example.test', REPORTER,
		/* protected */ false, /* enabled */ true, 'Reporter One' );
}

# ---- report ------------------------------------------------------------------
echo "\n=== SEED COMPLETE ===\n";
say( 'project_id',  $t_project_id );
say( 'category_id', $t_category_id );
say( 'bug_id',      $t_bug_id );
say( 'cf_id',       $t_cf_id );
say( 'cf_name',     $t_cf_name );
say( 'cf_read',     'VIEWER(10)' );
say( 'cf_write',    'MANAGER(70)' );
say( 'attacker',    "$t_user / $t_pass  (REPORTER=25)" );
