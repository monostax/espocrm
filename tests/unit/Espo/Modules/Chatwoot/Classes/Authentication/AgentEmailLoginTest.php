<?php

namespace tests\unit\Espo\Modules\Chatwoot\Classes\Authentication;

use Espo\Core\Api\Request;
use Espo\Core\Authentication\AuthToken\AuthToken;
use Espo\Core\Authentication\Helper\UserFinder;
use Espo\Core\Authentication\Login\Data;
use Espo\Core\Authentication\Logins\Espo;
use Espo\Core\Authentication\Result;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Classes\Authentication\AgentEmailLogin;
use Espo\Modules\Chatwoot\Services\ChatwootAgentIdentity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

class AgentEmailLoginTest extends TestCase
{
    public function testCanonicalEmailWorksBeforeChatwootProvisioningAndUsesNormalPasswordValidation(): void
    {
        $user = $this->createMock(User::class);
        foreach ([Result::success($user), Result::fail('CREDENTIALS')] as $result) {
            $finder = $this->createMock(UserFinder::class);
            $finder->expects($this->once())->method('find')->with('agent+one@example.com')->willReturn($user);
            $em = $this->createMock(EntityManager::class);
            $em->expects($this->never())->method('getRDBRepository');
            $identity = $this->createMock(ChatwootAgentIdentity::class);
            $identity->expects($this->never())->method('findCrmUser');
            $espo = $this->createMock(Espo::class);
            $request = $this->createMock(Request::class);
            $espo->expects($this->once())->method('login')->with($this->callback(fn (Data $data) =>
                $data->getUsername() === 'agent+one@example.com' && $data->getPassword() === 'password'
            ), $request)->willReturn($result);
            $login = new AgentEmailLogin($espo, $finder, $em, $identity);
            $this->assertSame($result, $login->login(new Data(' Agent+One@Example.com ', 'password'), $request));
        }
    }

    public function testTokenLoginIsPassedThroughWithoutChangingTokenOrUsername(): void
    {
        $finder = $this->createMock(UserFinder::class);
        $finder->expects($this->never())->method('find');
        $identity = $this->createMock(ChatwootAgentIdentity::class);
        $identity->expects($this->never())->method('findCrmUser');
        $data = new Data('Agent@Example.com', 'token', $this->createMock(AuthToken::class));
        $request = $this->createMock(Request::class);
        $espo = $this->createMock(Espo::class);
        $result = Result::fail();
        $espo->expects($this->once())->method('login')->with($this->identicalTo($data), $request)->willReturn($result);
        $login = new AgentEmailLogin($espo, $finder, $this->createMock(EntityManager::class), $identity);
        $this->assertSame($result, $login->login($data, $request));
    }
}
