<?php
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM – Open Source CRM application.
 * Copyright (C) 2014-2026 EspoCRM, Inc.
 * Website: https://www.espocrm.com
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU Affero General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU Affero General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

namespace tests\unit\Espo\Core\Mail\Account;

use Espo\Core\Mail\Account\GroupAccount\BouncedRecognizer;
use Espo\Core\Mail\MessageWrapper;
use Espo\Core\Mail\Parsers\MailMimeParser;

use Espo\ORM\EntityManager;

class BouncedRecognizerTest extends \PHPUnit\Framework\TestCase
{
    protected BouncedRecognizer $bouncedRecognizer;

    protected function setUp(): void
    {
        $this->bouncedRecognizer = new BouncedRecognizer();
    }

    private function createMessage(string $contents): MessageWrapper
    {
        $entityManager = $this->createMock(EntityManager::class);

        $parser = new MailMimeParser($entityManager);

        return new MessageWrapper(0, null, $parser, $contents);
    }

    public function testBounced1a(): void
    {
        $contents = file_get_contents('tests/unit/testData/Core/Mail/bounced_1.eml');

        $message = $this->createMessage($contents);

        $this->assertTrue($this->bouncedRecognizer->isBounced($message));
        $this->assertTrue($this->bouncedRecognizer->isHard($message));
        $this->assertEquals('0011aa', $this->bouncedRecognizer->extractQueueItemId($message));
    }

    public function testBounced1b(): void
    {
        $contents = file_get_contents('tests/unit/testData/Core/Mail/bounced_1.eml');
        $contents = str_replace('MAILER-DAEMON', 'test', $contents);

        $message = $this->createMessage($contents);

        $this->assertTrue($this->bouncedRecognizer->isBounced($message));
        $this->assertTrue($this->bouncedRecognizer->isHard($message));
    }

    public function testBounced2(): void
    {
        $contents = file_get_contents('tests/unit/testData/Core/Mail/bounced_2.eml');

        $message = $this->createMessage($contents);

        $this->assertTrue($this->bouncedRecognizer->isBounced($message));
        $this->assertFalse($this->bouncedRecognizer->isHard($message));
    }

    public function testNotBounced1(): void
    {
        $contents = file_get_contents('tests/unit/testData/Core/Mail/test_email_1.eml');

        $message = $this->createMessage($contents);

        $this->assertFalse($this->bouncedRecognizer->isBounced($message));
    }

    public function testBounced3(): void
    {
        $contents = file_get_contents('tests/unit/testData/Core/Mail/bounced_3.eml');

        $message = $this->createMessage($contents);

        $this->assertTrue($this->bouncedRecognizer->isBounced($message));
        $this->assertTrue($this->bouncedRecognizer->isHard($message));
        $this->assertEquals('5.4.1', $this->bouncedRecognizer->extractStatus($message));
    }

    public function testExtractFinalRecipientAndOriginalMessageIds(): void
    {
        $contents = file_get_contents('tests/unit/testData/Core/Mail/bounced_1.eml');
        $message = $this->createMessage($contents);

        $this->assertEquals('wrongster@test.com', $this->bouncedRecognizer->extractFinalRecipient($message));

        $ids = $this->bouncedRecognizer->extractOriginalMessageIds($message);

        $this->assertContains(
            '<000001372d0dbf88-76d26d51-9d96-468a-9071-318ba2c35003-000000@email.amazonses.com>',
            $ids
        );
    }

    public function testExtractOriginalMessageIdsFromRfc822Headers(): void
    {
        $contents = file_get_contents('tests/unit/testData/Core/Mail/bounced_3.eml');
        $message = $this->createMessage($contents);

        $this->assertEquals('receiver@test.com', $this->bouncedRecognizer->extractFinalRecipient($message));

        $ids = $this->bouncedRecognizer->extractOriginalMessageIds($message);

        $this->assertContains(
            '<CAKouvC4fBcE7qfvhEq0-qOtsD45mWckUfhtr7KHhobwwvy0thA@mail.gmail.com>',
            $ids
        );
    }
}
