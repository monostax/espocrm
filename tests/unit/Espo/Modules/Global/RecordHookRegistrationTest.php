<?php

namespace tests\unit\Espo\Modules\Global;

use Espo\Core\Record\Hook\Provider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Espo reads record hooks only from `<type>HookClassNameList` keys whose type
 * it knows (Espo\Core\Record\Hook\Provider::$typeInterfaceListMap). Any other
 * key, e.g. `beforeSaveHookClassNameList`, is silently ignored, so the hook
 * never runs. CustomFieldDef shipped like that and saved fields without a
 * valueKey (values stored under "").
 */
class RecordHookRegistrationTest extends TestCase
{
    public function testEveryCustomRecordHookUsesASupportedTypeAndInterface(): void
    {
        /** @var array<string, class-string[]> $map */
        $map = (new ReflectionClass(Provider::class))
            ->getProperty('typeInterfaceListMap')
            ->getDefaultValue();

        $root = dirname(__DIR__, 5);
        $files = array_merge(
            glob($root . '/custom/Espo/Modules/*/Resources/metadata/recordDefs/*.json') ?: [],
            glob($root . '/custom/Espo/Modules/*/Resources/metadata/app/record.json') ?: [],
        );

        $this->assertNotEmpty($files);

        $problems = [];
        $checked = 0;

        foreach ($files as $file) {
            $data = json_decode((string) file_get_contents($file), true);

            if (!is_array($data)) {
                continue;
            }

            $short = substr($file, strlen($root) + 1);

            foreach ($data as $key => $classList) {
                if (!preg_match('/^([a-zA-Z]+)HookClassNameList$/', (string) $key, $m)) {
                    continue;
                }

                $type = $m[1];

                if (!isset($map[$type])) {
                    $problems[] = "$short: unsupported hook type key '$key'";

                    continue;
                }

                foreach ((array) $classList as $className) {
                    if ($className === '__APPEND__') {
                        continue;
                    }

                    $checked++;

                    if (!class_exists($className)) {
                        $problems[] = "$short: $key lists missing class $className";

                        continue;
                    }

                    $implements = array_filter(
                        $map[$type],
                        fn (string $interface) => is_subclass_of($className, $interface)
                    );

                    if ($implements === []) {
                        $problems[] = "$short: $className does not implement any of " .
                            implode(', ', $map[$type]) . " required by $key";
                    }
                }
            }
        }

        $this->assertGreaterThan(0, $checked);
        $this->assertSame([], $problems, implode("\n", $problems));
    }
}
