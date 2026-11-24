<?php

declare(strict_types=1);

use App\Data\DefaultsSnapshot;

// Cut down from a real `defaults read`.
const DEFAULTS_DUMP = <<<'DUMP'
    {
        "Apple Global Domain" =     {
            AppleLanguages =         (
                "en-US"
            );
            "Key \"Repeat\"" = 2;
        };
        "com.apple.dock" =     {
            "persistent-apps" =         (
                    {
                    "tile-type" = "file-tile";
                }
            );
            tilesize = 48;
        };
    }
    DUMP;

it('reads each domain and key, with every line of a value that spans lines', function () {
    expect(DefaultsSnapshot::parse(DEFAULTS_DUMP)->settings)->toBe([
        'NSGlobalDomain' => [
            'AppleLanguages' => "(\n            \"en-US\"\n        );",
            'Key "Repeat"' => '2;',
        ],
        'com.apple.dock' => [
            'persistent-apps' => "(\n                {\n                \"tile-type\" = \"file-tile\";\n            }\n        );",
            'tilesize' => '48;',
        ],
    ]);
});

it('turns the \U escapes in a quoted name back into the letters they stand for', function () {
    $dump = <<<'DUMP'
        {
            "com.example.caf\U00e9" =     {
                "smile \Ud83d\Ude00" = 1;
                "C:\\U00e9" = 2;
            };
        }
        DUMP;

    expect(DefaultsSnapshot::parse($dump)->settings)->toBe([
        "com.example.caf\u{e9}" => [
            "smile \u{1F600}" => '1;',
            'C:\U00e9' => '2;',
        ],
    ]);
});

it('lists the settings that are new or print differently, and not the deleted ones', function () {
    $before = DefaultsSnapshot::parse(DEFAULTS_DUMP);
    $after = DefaultsSnapshot::parse(str_replace(
        ['"file-tile"', 'tilesize = 48;', "        \"Key \\\"Repeat\\\"\" = 2;\n"],
        ['"folder-tile"', "tilesize = 48;\n        autohide = 1;", ''],
        DEFAULTS_DUMP,
    ));

    expect($after->changedSince($before))->toBe([
        ['com.apple.dock', 'persistent-apps'],
        ['com.apple.dock', 'autohide'],
    ]);
});
