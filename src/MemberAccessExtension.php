<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess;

use MailAddress;
use MediaWiki\Context\RequestContext;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Session\CsrfTokenSet;
use ProfessionalWiki\MemberAccess\Application\AddEntriesUseCase;
use ProfessionalWiki\MemberAccess\Application\AllowlistMatcher;
use ProfessionalWiki\MemberAccess\Application\AllowlistRepository;
use ProfessionalWiki\MemberAccess\Application\CreateGroupUseCase;
use ProfessionalWiki\MemberAccess\Application\CodeHasher;
use ProfessionalWiki\MemberAccess\Application\CodeLifetime;
use ProfessionalWiki\MemberAccess\Application\CodeLoginMode;
use ProfessionalWiki\MemberAccess\Application\CodeMailer;
use ProfessionalWiki\MemberAccess\Application\CodeRepository;
use ProfessionalWiki\MemberAccess\Application\CounterStore;
use ProfessionalWiki\MemberAccess\Application\DeactivateMemberUseCase;
use ProfessionalWiki\MemberAccess\Application\DeleteGroupUseCase;
use ProfessionalWiki\MemberAccess\Application\MemberBlocker;
use ProfessionalWiki\MemberAccess\Application\MemberGroupRepository;
use ProfessionalWiki\MemberAccess\Application\MemberRemover;
use ProfessionalWiki\MemberAccess\Application\MemberRepository;
use ProfessionalWiki\MemberAccess\Application\ReactivateMemberUseCase;
use ProfessionalWiki\MemberAccess\Application\RemoveMemberUseCase;
use ProfessionalWiki\MemberAccess\Application\RenameGroupUseCase;
use ProfessionalWiki\MemberAccess\Application\RandomSecretGenerator;
use ProfessionalWiki\MemberAccess\Application\RequestCodeUseCase;
use ProfessionalWiki\MemberAccess\Application\RequestThrottle;
use ProfessionalWiki\MemberAccess\Application\Schema;
use ProfessionalWiki\MemberAccess\Application\SecretGenerator;
use ProfessionalWiki\MemberAccess\Application\VerifyCodeUseCase;
use ProfessionalWiki\MemberAccess\EntryPoints\Auth\MemberAuthenticationProvider;
use ProfessionalWiki\MemberAccess\EntryPoints\Auth\MemberProvisioner;
use ProfessionalWiki\MemberAccess\EntryPoints\Auth\PasswordResetHandler;
use ProfessionalWiki\MemberAccess\EntryPoints\MemberLoginHandler;
use ProfessionalWiki\MemberAccess\EntryPoints\REST\AddEntriesApi;
use ProfessionalWiki\MemberAccess\EntryPoints\REST\CreateGroupApi;
use ProfessionalWiki\MemberAccess\EntryPoints\REST\DeactivateMemberApi;
use ProfessionalWiki\MemberAccess\EntryPoints\REST\DeleteGroupApi;
use ProfessionalWiki\MemberAccess\EntryPoints\REST\ListEntriesApi;
use ProfessionalWiki\MemberAccess\EntryPoints\REST\ListGroupsApi;
use ProfessionalWiki\MemberAccess\EntryPoints\REST\ListMembersApi;
use ProfessionalWiki\MemberAccess\EntryPoints\REST\ReactivateMemberApi;
use ProfessionalWiki\MemberAccess\EntryPoints\REST\RemoveEntryApi;
use ProfessionalWiki\MemberAccess\EntryPoints\REST\RemoveMemberApi;
use ProfessionalWiki\MemberAccess\EntryPoints\REST\RenameGroupApi;
use ProfessionalWiki\MemberAccess\EntryPoints\Auth\SsoAuthorizationHandler;
use ProfessionalWiki\MemberAccess\EntryPoints\UserListApiHandler;
use ProfessionalWiki\MemberAccess\Persistence\DatabaseAllowlistRepository;
use ProfessionalWiki\MemberAccess\Persistence\DatabaseMemberGroupRepository;
use ProfessionalWiki\MemberAccess\Persistence\DatabaseMemberRepository;
use ProfessionalWiki\MemberAccess\Persistence\DatabaseSchema;
use ProfessionalWiki\MemberAccess\Persistence\DeferredCodeMailer;
use ProfessionalWiki\MemberAccess\Persistence\MediaWikiCodeMailer;
use ProfessionalWiki\MemberAccess\Persistence\MediaWikiMemberBlocker;
use ProfessionalWiki\MemberAccess\Persistence\MediaWikiMemberRemover;
use ProfessionalWiki\MemberAccess\Persistence\StashCodeRepository;
use ProfessionalWiki\MemberAccess\Persistence\StashCounterStore;
use Psr\Log\LoggerInterface;
use Wikimedia\ObjectCache\BagOStuff;
use Wikimedia\Rdbms\IConnectionProvider;

class MemberAccessExtension {

	private ?BagOStuff $stashOverride = null;

	private ?SecretGenerator $secretGeneratorOverride = null;

	private ?LoggerInterface $loggerOverride = null;

	private ?Schema $schemaOverride = null;

	private ?Schema $schema = null;

	public static function getInstance(): self {
		/** @var ?self $instance */
		static $instance = null;
		$instance ??= new self();
		return $instance;
	}

	public function setStashOverride( ?BagOStuff $stash ): void {
		$this->stashOverride = $stash;
	}

	public function setSecretGeneratorOverride( ?SecretGenerator $generator ): void {
		$this->secretGeneratorOverride = $generator;
	}

	public function setLoggerOverride( ?LoggerInterface $logger ): void {
		$this->loggerOverride = $logger;
	}

	public function setSchemaOverride( ?Schema $schema ): void {
		$this->schemaOverride = $schema;
	}

	public static function newMemberAuthenticationProvider(): MemberAuthenticationProvider {
		return self::getInstance()->newAuthenticationProvider();
	}

	public function newAuthenticationProvider(): MemberAuthenticationProvider {
		return new MemberAuthenticationProvider(
			mode: $this->getCodeLoginMode(),
			codeRequests: $this->newRequestCodeUseCase(),
			codeVerification: $this->newVerifyCodeUseCase(),
			matcher: $this->newAllowlistMatcher(),
			members: $this->newMemberRepository(),
			provisioner: $this->newMemberProvisioner(),
			userLookup: MediaWikiServices::getInstance()->getUserIdentityLookup(),
			userGroups: MediaWikiServices::getInstance()->getUserGroupManager(),
			auditLogger: $this->newLogger(),
			codeLifetime: $this->newCodeLifetime(),
			readerGroup: $this->getReaderGroup(),
			schema: $this->getSchema()
		);
	}

	public static function newSsoAuthorizationHandlerHookHandler(): SsoAuthorizationHandler {
		return self::getInstance()->newSsoAuthorizationHandler();
	}

	private function newSsoAuthorizationHandler(): SsoAuthorizationHandler {
		return new SsoAuthorizationHandler(
			allowlistApplies: $this->allowlistAppliesToSso(),
			matcher: $this->newAllowlistMatcher(),
			members: $this->newMemberRepository(),
			userGroups: MediaWikiServices::getInstance()->getUserGroupManager(),
			authManager: MediaWikiServices::getInstance()->getAuthManager(),
			logger: $this->newLogger(),
			readerGroup: $this->getReaderGroup(),
			schema: $this->getSchema()
		);
	}

	public static function newListGroupsApi(): ListGroupsApi {
		$instance = self::getInstance();

		return new ListGroupsApi(
			csrfTokens: $instance->newCsrfTokenSet(),
			schema: $instance->getSchema(),
			groups: $instance->newMemberGroupRepository(),
			allowlist: $instance->newAllowlistRepository(),
			members: $instance->newMemberRepository()
		);
	}

	public static function newCreateGroupApi(): CreateGroupApi {
		$instance = self::getInstance();

		return new CreateGroupApi(
			csrfTokens: $instance->newCsrfTokenSet(),
			schema: $instance->getSchema(),
			useCase: new CreateGroupUseCase( groups: $instance->newMemberGroupRepository() )
		);
	}

	public static function newRenameGroupApi(): RenameGroupApi {
		$instance = self::getInstance();

		return new RenameGroupApi(
			csrfTokens: $instance->newCsrfTokenSet(),
			schema: $instance->getSchema(),
			useCase: new RenameGroupUseCase( groups: $instance->newMemberGroupRepository() )
		);
	}

	public static function newDeleteGroupApi(): DeleteGroupApi {
		$instance = self::getInstance();

		return new DeleteGroupApi(
			csrfTokens: $instance->newCsrfTokenSet(),
			schema: $instance->getSchema(),
			useCase: $instance->newDeleteGroupUseCase()
		);
	}

	public static function newListEntriesApi(): ListEntriesApi {
		$instance = self::getInstance();

		return new ListEntriesApi(
			csrfTokens: $instance->newCsrfTokenSet(),
			schema: $instance->getSchema(),
			groups: $instance->newMemberGroupRepository(),
			allowlist: $instance->newAllowlistRepository()
		);
	}

	public static function newAddEntriesApi(): AddEntriesApi {
		$instance = self::getInstance();

		return new AddEntriesApi(
			csrfTokens: $instance->newCsrfTokenSet(),
			schema: $instance->getSchema(),
			useCase: new AddEntriesUseCase(
				groups: $instance->newMemberGroupRepository(),
				allowlist: $instance->newAllowlistRepository()
			),
			actors: MediaWikiServices::getInstance()->getActorNormalization(),
			connectionProvider: $instance->getConnectionProvider()
		);
	}

	public static function newRemoveEntryApi(): RemoveEntryApi {
		$instance = self::getInstance();

		return new RemoveEntryApi(
			csrfTokens: $instance->newCsrfTokenSet(),
			schema: $instance->getSchema(),
			allowlist: $instance->newAllowlistRepository()
		);
	}

	public static function newListMembersApi(): ListMembersApi {
		$instance = self::getInstance();

		return new ListMembersApi(
			csrfTokens: $instance->newCsrfTokenSet(),
			schema: $instance->getSchema(),
			members: $instance->newMemberRepository(),
			groups: $instance->newMemberGroupRepository()
		);
	}

	public static function newDeactivateMemberApi(): DeactivateMemberApi {
		$instance = self::getInstance();

		return new DeactivateMemberApi(
			csrfTokens: $instance->newCsrfTokenSet(),
			schema: $instance->getSchema(),
			useCase: $instance->newDeactivateMemberUseCase()
		);
	}

	public static function newRemoveMemberApi(): RemoveMemberApi {
		$instance = self::getInstance();

		return new RemoveMemberApi(
			csrfTokens: $instance->newCsrfTokenSet(),
			schema: $instance->getSchema(),
			useCase: $instance->newRemoveMemberUseCase()
		);
	}

	public static function newReactivateMemberApi(): ReactivateMemberApi {
		$instance = self::getInstance();

		return new ReactivateMemberApi(
			csrfTokens: $instance->newCsrfTokenSet(),
			schema: $instance->getSchema(),
			useCase: $instance->newReactivateMemberUseCase()
		);
	}

	private function newCsrfTokenSet(): CsrfTokenSet {
		return new CsrfTokenSet( RequestContext::getMain()->getRequest() );
	}

	public static function newUserListApiHookHandler(): UserListApiHandler {
		return self::getInstance()->newUserListApiHandler();
	}

	private function newUserListApiHandler(): UserListApiHandler {
		return new UserListApiHandler(
			userGroups: MediaWikiServices::getInstance()->getUserGroupManager(),
			readerGroup: $this->getReaderGroup(),
			blockedModules: $this->getBlockedApiModules()
		);
	}

	public static function newMemberLoginHookHandler(): MemberLoginHandler {
		return self::getInstance()->newMemberLoginHandler();
	}

	private function newMemberLoginHandler(): MemberLoginHandler {
		return new MemberLoginHandler( members: $this->newMemberRepository(), schema: $this->getSchema() );
	}

	public static function newPasswordResetHookHandler(): PasswordResetHandler {
		return self::getInstance()->newPasswordResetHandler();
	}

	private function newPasswordResetHandler(): PasswordResetHandler {
		return new PasswordResetHandler(
			members: $this->newMemberRepository(),
			userGroups: MediaWikiServices::getInstance()->getUserGroupManager(),
			readerGroup: $this->getReaderGroup(),
			schema: $this->getSchema()
		);
	}

	private function newMemberProvisioner(): MemberProvisioner {
		return new MemberProvisioner(
			members: $this->newMemberRepository(),
			userGroups: MediaWikiServices::getInstance()->getUserGroupManager(),
			logger: $this->newLogger(),
			readerGroup: $this->getReaderGroup()
		);
	}

	public function newRequestCodeUseCase(): RequestCodeUseCase {
		return new RequestCodeUseCase(
			mode: $this->getCodeLoginMode(),
			matcher: $this->newAllowlistMatcher(),
			members: $this->newMemberRepository(),
			throttle: $this->newRequestThrottle(),
			generator: $this->newSecretGenerator(),
			hasher: $this->newCodeHasher(),
			codes: $this->newCodeRepository(),
			mailer: $this->newCodeMailer(),
			logger: $this->newLogger(),
			codeLifetime: $this->newCodeLifetime()
		);
	}

	public function newVerifyCodeUseCase(): VerifyCodeUseCase {
		return new VerifyCodeUseCase(
			codes: $this->newCodeRepository(),
			counters: $this->newCounterStore(),
			hasher: $this->newCodeHasher(),
			logger: $this->newLogger(),
			codeLifetime: $this->newCodeLifetime(),
			attemptLimit: $this->getCodeAttemptLimit()
		);
	}

	public function newAllowlistMatcher(): AllowlistMatcher {
		return new AllowlistMatcher( allowlist: $this->newAllowlistRepository() );
	}

	private function newDeleteGroupUseCase(): DeleteGroupUseCase {
		return new DeleteGroupUseCase(
			groups: $this->newMemberGroupRepository(),
			allowlist: $this->newAllowlistRepository(),
			members: $this->newMemberRepository()
		);
	}

	public function newDeactivateMemberUseCase(): DeactivateMemberUseCase {
		return new DeactivateMemberUseCase(
			members: $this->newMemberRepository(),
			blocker: $this->newMemberBlocker(),
			logger: $this->newLogger()
		);
	}

	public function newRemoveMemberUseCase(): RemoveMemberUseCase {
		return new RemoveMemberUseCase(
			members: $this->newMemberRepository(),
			remover: $this->newMemberRemover(),
			logger: $this->newLogger()
		);
	}

	private function newMemberRemover(): MemberRemover {
		$services = MediaWikiServices::getInstance();

		return new MediaWikiMemberRemover(
			connectionProvider: $this->getConnectionProvider(),
			members: $this->newMemberRepository(),
			userFactory: $services->getUserFactory(),
			userLookup: $services->getUserIdentityLookup(),
			logger: $this->newLogger()
		);
	}

	private function newReactivateMemberUseCase(): ReactivateMemberUseCase {
		return new ReactivateMemberUseCase(
			members: $this->newMemberRepository(),
			blocker: $this->newMemberBlocker(),
			logger: $this->newLogger()
		);
	}

	public function newMemberBlocker(): MemberBlocker {
		$services = MediaWikiServices::getInstance();

		return new MediaWikiMemberBlocker(
			blockUserFactory: $services->getBlockUserFactory(),
			unblockUserFactory: $services->getUnblockUserFactory(),
			blockStore: $services->getDatabaseBlockStore(),
			userFactory: $services->getUserFactory(),
			logger: $this->newLogger()
		);
	}

	public function newMemberGroupRepository(): MemberGroupRepository {
		return new DatabaseMemberGroupRepository( connectionProvider: $this->getConnectionProvider() );
	}

	public function newAllowlistRepository(): AllowlistRepository {
		return new DatabaseAllowlistRepository( connectionProvider: $this->getConnectionProvider() );
	}

	public function newMemberRepository(): MemberRepository {
		return new DatabaseMemberRepository( connectionProvider: $this->getConnectionProvider() );
	}

	private function newRequestThrottle(): RequestThrottle {
		return new RequestThrottle(
			counters: $this->newCounterStore(),
			emailBurstLimit: $this->getIntConfig( 'MemberAccessEmailBurstLimit' ),
			emailDailyLimit: $this->getIntConfig( 'MemberAccessEmailDailyLimit' ),
			ipBurstLimit: $this->getIntConfig( 'MemberAccessIpBurstLimit' ),
			ipDailyLimit: $this->getIntConfig( 'MemberAccessIpDailyLimit' )
		);
	}

	private function newSecretGenerator(): SecretGenerator {
		return $this->secretGeneratorOverride ?? new RandomSecretGenerator();
	}

	private function newCodeRepository(): CodeRepository {
		return new StashCodeRepository( stash: $this->getStash() );
	}

	private function newCounterStore(): CounterStore {
		return new StashCounterStore( stash: $this->getStash(), logger: $this->newLogger() );
	}

	private function newCodeHasher(): CodeHasher {
		return new CodeHasher( secret: $this->getStringConfig( 'SecretKey' ) );
	}

	private function newCodeMailer(): CodeMailer {
		return new DeferredCodeMailer(
			mailer: new MediaWikiCodeMailer(
				emailer: MediaWikiServices::getInstance()->getEmailer(),
				sender: $this->getSenderAddress(),
				logger: $this->newLogger()
			)
		);
	}

	public function getSenderAddress(): MailAddress {
		$sender = $this->getConfigValue( 'MemberAccessSenderAddress' );

		if ( is_string( $sender ) && $sender !== '' ) {
			return new MailAddress( $sender );
		}

		return new MailAddress( $this->getStringConfig( 'PasswordSender' ) );
	}

	private function newLogger(): LoggerInterface {
		return $this->loggerOverride ?? LoggerFactory::getInstance( 'MemberAccess' );
	}

	private function getStash(): BagOStuff {
		return $this->stashOverride ?? MediaWikiServices::getInstance()->getMainObjectStash();
	}

	private function getConnectionProvider(): IConnectionProvider {
		return MediaWikiServices::getInstance()->getConnectionProvider();
	}

	/**
	 * Held on to rather than built anew, so that the one question every entry point asks is asked
	 * of the database once per request, and answered from memory after that.
	 */
	private function getSchema(): Schema {
		if ( $this->schemaOverride !== null ) {
			return $this->schemaOverride;
		}

		$this->schema ??= new DatabaseSchema(
			connectionProvider: $this->getConnectionProvider(),
			logger: $this->newLogger()
		);

		return $this->schema;
	}

	public function getReaderGroup(): string {
		return $this->getStringConfig( 'MemberAccessReaderGroup' );
	}

	private function getCodeLoginMode(): CodeLoginMode {
		$configured = $this->getStringConfig( 'MemberAccessCodeLogin' );

		// Only null and other non-scalars read as an empty string, since the default is "off", so
		// empty means unset-ish rather than a typo worth warning about.
		if ( $configured !== '' && CodeLoginMode::tryFrom( $configured ) === null ) {
			$this->newLogger()->warning(
				'$wgMemberAccessCodeLogin holds an unknown value and is read as "off"',
				[ 'value' => $configured ]
			);
		}

		return CodeLoginMode::fromSetting( $configured );
	}

	/**
	 * Only an explicit true holds single sign-on to the allowlist. A wiki that set nothing has that
	 * route left alone, like every other route here: admitting anybody is a setting, never a default.
	 */
	private function allowlistAppliesToSso(): bool {
		return $this->getConfigValue( 'MemberAccessApplyAllowlistToSso' ) === true;
	}

	public function newCodeLifetime(): CodeLifetime {
		return new CodeLifetime( $this->getIntConfig( 'MemberAccessCodeTtlSeconds' ) );
	}

	public function getCodeAttemptLimit(): int {
		return $this->getIntConfig( 'MemberAccessCodeAttemptLimit' );
	}

	/**
	 * @return string[]
	 */
	private function getBlockedApiModules(): array {
		$value = $this->getConfigValue( 'MemberAccessBlockedApiModules' );
		$modules = [];

		foreach ( is_array( $value ) ? $value : [] as $module ) {
			if ( is_string( $module ) ) {
				$modules[] = $module;
			}
		}

		return $modules;
	}

	private function getStringConfig( string $name ): string {
		$value = $this->getConfigValue( $name );

		return is_scalar( $value ) ? strval( $value ) : '';
	}

	private function getIntConfig( string $name ): int {
		$value = $this->getConfigValue( $name );

		return is_scalar( $value ) ? intval( $value ) : 0;
	}

	private function getConfigValue( string $name ): mixed {
		return MediaWikiServices::getInstance()->getMainConfig()->get( $name );
	}

}
