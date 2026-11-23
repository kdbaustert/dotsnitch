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
