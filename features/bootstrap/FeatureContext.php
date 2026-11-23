<?php

use Behat\Behat\Context\Context;
use Behat\Gherkin\Node\PyStringNode;

/**
 * Commands run inside the test wiki container (docker-compose.yml),
 * in /var/www/html unless a step changes that. Override the prefix with MW_CLI_EXEC.
 */
class FeatureContext implements Context {
	private string $stdout = '';
	private string $stderr = '';
	private int $code = 0;

	/**
	 * @BeforeScenario
	 */
	public function resetWiki(): void {
		$this->exec( 'reset-wiki' );
		if ( $this->code !== 0 ) {
			throw new RuntimeException( "Could not reset the test wiki. Is it running (docker compose up -d)?\n$this->stderr" );
		}
	}

	/**
	 * @Given a wiki
	 */
	public function aWiki(): void {
		// Reset by resetWiki(); the step exists so scenarios state their precondition.
	}

	/**
	 * @Given a file :path with:
	 */
	public function aFileWith( string $path, PyStringNode $content ): void {
		$this->exec( 'mkdir -p "$(dirname ' . escapeshellarg( $path ) . ')" && cat > ' . escapeshellarg( $path ), $content->getRaw() . "\n" );
	}

	/**
	 * @When I run :command
	 */
	public function iRun( string $command ): void {
		$this->exec( $command );
		if ( $this->code !== 0 ) {
			throw new RuntimeException( "'$command' failed with code $this->code.\nSTDOUT:\n$this->stdout\nSTDERR:\n$this->stderr" );
		}
	}

	/**
	 * @When I try :command
	 */
	public function iTry( string $command ): void {
		$this->exec( $command );
	}

	/**
	 * @Then STDOUT should be:
	 */
	public function stdoutShouldBe( PyStringNode $expected ): void {
		$this->assertSame( $expected->getRaw(), rtrim( $this->stdout, "\n" ), 'STDOUT' );
	}

	/**
	 * @Then STDOUT should contain:
	 */
	public function stdoutShouldContain( PyStringNode $expected ): void {
		$this->assertContains( $expected->getRaw(), $this->stdout, 'STDOUT' );
	}

	/**
	 * @Then STDERR should contain:
	 */
	public function stderrShouldContain( PyStringNode $expected ): void {
		$this->assertContains( $expected->getRaw(), $this->stderr, 'STDERR' );
	}

	/**
	 * @Then the return code should be :code
	 */
	public function theReturnCodeShouldBe( int $code ): void {
		$this->assertSame( (string)$code, (string)$this->code, 'Return code' );
	}

	private function exec( string $command, ?string $stdin = null ): void {
		$prefix = getenv( 'MW_CLI_EXEC' ) ?: 'docker compose exec -T -w /var/www/html wiki';
		// stderr goes to a file: reading two pipes one after the other can deadlock.
		$stderr = tempnam( sys_get_temp_dir(), 'mw-cli-stderr' );
		$proc = proc_open(
			"$prefix sh -c " . escapeshellarg( $command ),
			[ [ 'pipe', 'r' ], [ 'pipe', 'w' ], [ 'file', $stderr, 'w' ] ],
			$pipes,
			dirname( __DIR__, 2 )
		);
		fwrite( $pipes[0], $stdin ?? '' );
		fclose( $pipes[0] );
		$this->stdout = stream_get_contents( $pipes[1] );
		$this->code = proc_close( $proc );
		$this->stderr = file_get_contents( $stderr );
		unlink( $stderr );
	}

	private function assertSame( string $expected, string $actual, string $what ): void {
		if ( $expected !== $actual ) {
			throw new RuntimeException( "$what is:\n$actual\n\nExpected:\n$expected\n\nSTDERR:\n$this->stderr" );
		}
	}

	private function assertContains( string $needle, string $haystack, string $what ): void {
		if ( !str_contains( $haystack, $needle ) ) {
			throw new RuntimeException( "$what does not contain:\n$needle\n\n$what:\n$haystack" );
		}
	}
}
