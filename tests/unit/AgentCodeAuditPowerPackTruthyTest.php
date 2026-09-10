<?php
/**
 * Regression test for the Power Pack flags' truthiness read (Task 3 review
 * round-1, Important finding): power_pack_env() must read
 * AURA_POWER_EXECUTE_PHP (and its siblings) the same way the Power Pack's
 * own tools gate on them — by TRUTHINESS, not a strict `true ===` compare.
 * siteagent-power-pack class-tool-execute-php.php:153,
 * class-tool-fs-write.php:195 and class-tool-wp-cli.php:209 all read
 * `defined( 'X' ) && X`, so `define( 'AURA_POWER_EXECUTE_PHP', 1 )` arms
 * exec on the real plugin. This proves the audit tool reports that site as
 * `execute_php: true` too, through the REAL power_pack_env() — not a test
 * override.
 *
 * Isolated in its own file, in its own process: a PHP constant can never be
 * undefined once set, and AgentCodeAuditTest::
 * test_power_pack_absent_is_installed_false_with_every_flag_false() needs
 * AURA_POWER_PACK_VERSION to stay UNDEFINED for the rest of that shared
 * suite process — defining it there would poison that test, and every test
 * after it, for good. @runInSeparateProcess forks a fresh PHP process, which
 * reloads phpunit.xml.dist's bootstrap="tests/bootstrap.php" on its own —
 * the same reason ElementorDoorSnapshotsTest and ElementorDoorCreationTest
 * already isolate a define() this way — so the constants defined here never
 * reach any other test file or method.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

require_once SA_PLUGIN_DIR . '/includes/tools/class-tool-audit-agent-code.php';

final class AgentCodeAuditPowerPackTruthyTest extends TestCase {

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_truthy_non_boolean_define_reports_execute_php_true(): void {
		// Defensive: a constant cannot be undefined, so if some other path in
		// this fresh process already defined either constant with a value
		// this test does not expect, skip rather than silently assert the
		// wrong thing or fatal on a duplicate define().
		if ( defined( 'AURA_POWER_EXECUTE_PHP' ) && 1 !== AURA_POWER_EXECUTE_PHP ) {
			$this->markTestSkipped( 'AURA_POWER_EXECUTE_PHP is already defined with a different value in this process.' );
		}
		if ( defined( 'AURA_POWER_PACK_VERSION' ) && '0.2.3' !== AURA_POWER_PACK_VERSION ) {
			$this->markTestSkipped( 'AURA_POWER_PACK_VERSION is already defined with a different value in this process.' );
		}
		if ( ! defined( 'AURA_POWER_PACK_VERSION' ) ) {
			define( 'AURA_POWER_PACK_VERSION', '0.2.3' );
		}
		if ( ! defined( 'AURA_POWER_EXECUTE_PHP' ) ) {
			define( 'AURA_POWER_EXECUTE_PHP', 1 ); // truthy, not strictly `true` — the shape a real wp-config constant often takes
		}

		sa_reset_state();

		$r = ( new Aura_Tool_Audit_Agent_Code() )->execute( array() );

		$this->assertTrue( $r['power_pack']['installed'] );
		$this->assertSame( '0.2.3', $r['power_pack']['version'] );
		$this->assertTrue( $r['power_pack']['execute_php'], 'a truthy non-boolean define must audit as armed, matching the Power Pack\'s own gate' );
		$this->assertFalse( $r['power_pack']['fs_write'] );
		$this->assertFalse( $r['power_pack']['wp_cli'] );
	}
}
