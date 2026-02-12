<?php

namespace Espo\Modules\PackEnterprise\Services;

use Espo\Core\AclManager;
use Espo\Core\InjectableFactory;
use Espo\Entities\User;
use Espo\Modules\PackEnterprise\Core\GoogleCalendar\Actions\CalendarSync;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Tools\OAuth\TokensProvider;
use Espo\Tools\OAuth\Exceptions\AccountNotFound;
use Espo\Tools\OAuth\Exceptions\NoToken;
use Espo\Tools\OAuth\Exceptions\ProviderNotAvailable;
use Psr\Log\LoggerInterface;

/**
 * Service for MsxGoogleCalendar operations.
 * Uses OAuthAccount + TokensProvider instead of ExternalAccount + ServiceFactory.
 */
class MsxGoogleCalendar
{
    private ?CalendarSync $calendarSyncManager = null;

    private EntityManager $entityManager;
    private InjectableFactory $injectableFactory;
    private AclManager $aclManager;
    private User $user;
    private TokensProvider $tokensProvider;
    private LoggerInterface $log;

    public function __construct(
        EntityManager $entityManager,
        InjectableFactory $injectableFactory,
        AclManager $aclManager,
        User $user,
        TokensProvider $tokensProvider,
        LoggerInterface $log
    ) {
        $this->entityManager = $entityManager;
        $this->injectableFactory = $injectableFactory;
        $this->aclManager = $aclManager;
        $this->user = $user;
        $this->tokensProvider = $tokensProvider;
        $this->log = $log;
    }

    protected function getCalendarSyncManager(): CalendarSync
    {
        if (!$this->calendarSyncManager) {
            $this->calendarSyncManager = $this->injectableFactory->create(CalendarSync::class);
        }

        return $this->calendarSyncManager;
    }

    /**
     * Get the list of Google calendars for the current user.
     * Requires at least one active MsxGoogleCalendarUser record.
     *
     * @return array<string, string>
     */
    public function usersCalendars(): array
    {
        $userId = $this->user->getId();

        // Find the user's active MsxGoogleCalendarUser record to get the oAuthAccountId.
        $calendarUser = $this->entityManager
            ->getRDBRepository('MsxGoogleCalendarUser')
            ->where([
                'userId' => $userId,
                'active' => true,
            ])
            ->findOne();

        if (!$calendarUser || !$calendarUser->get('oAuthAccountId')) {
            return [];
        }

        $calendarManager = $this->getCalendarSyncManager();
        $calendarManager->setUserId($userId);
        $calendarManager->setOAuthAccountId($calendarUser->get('oAuthAccountId'));

        return $calendarManager->getCalendarList();
    }

    /**
     * Get the list of Google calendars for a specific OAuthAccount.
     * Used during record creation/editing when no MsxGoogleCalendarUser record
     * exists yet or when configuring a specific OAuthAccount.
     *
     * @return array<string, string>
     */
    public function usersCalendarsByOAuthAccount(string $oAuthAccountId): array
    {
        $userId = $this->user->getId();

        // Verify the OAuth account is connected by trying to get tokens.
        try {
            $this->tokensProvider->get($oAuthAccountId);
        } catch (AccountNotFound | NoToken | ProviderNotAvailable $e) {
            $this->log->warning(
                'MsxGoogleCalendar: Could not fetch calendars for OAuthAccount ' .
                $oAuthAccountId . ': ' . $e->getMessage()
            );

            return [];
        } catch (\Exception $e) {
            $this->log->error(
                'MsxGoogleCalendar: Token error for OAuthAccount ' .
                $oAuthAccountId . ': ' . $e->getMessage()
            );

            return [];
        }

        $calendarManager = $this->getCalendarSyncManager();
        $calendarManager->setUserId($userId);
        $calendarManager->setOAuthAccountId($oAuthAccountId);

        return $calendarManager->getCalendarList();
    }

    /**
     * Sync a specific calendar (MsxGoogleCalendarUser record).
     */
    public function syncCalendar(Entity $calendarUser): void
    {
        /** @var ?User $user */
        $user = $this->entityManager->getEntityById('User', $calendarUser->get('userId'));

        if (!$user || !$user->get('isActive')) {
            return;
        }

        if (!$this->aclManager->check($user, 'MsxGoogleCalendar')) {
            return;
        }

        $oAuthAccountId = $calendarUser->get('oAuthAccountId');

        if (!$oAuthAccountId || !$calendarUser->get('active')) {
            return;
        }

        // Verify the OAuth account is connected by trying to get tokens.
        try {
            $this->tokensProvider->get($oAuthAccountId);
        } catch (AccountNotFound | NoToken | ProviderNotAvailable $e) {
            $this->log->error(
                'MsxGoogleCalendar Sync: User \'' . ($calendarUser->get('userName') ?? $calendarUser->get('userId')) .
                '\' could not connect to Google when syncing. ' . $e->getMessage()
            );

            return;
        } catch (\Exception $e) {
            $this->log->error(
                'MsxGoogleCalendar Sync: Token error for user ' . $calendarUser->get('userId') .
                ': ' . $e->getMessage()
            );

            return;
        }

        $calendarManager = $this->getCalendarSyncManager();
        $calendarManager->setUserId($calendarUser->get('userId'));
        $calendarManager->setOAuthAccountId($oAuthAccountId);

        $calendarManager->run($calendarUser);
    }
}
