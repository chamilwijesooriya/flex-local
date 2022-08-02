# Symfony Flex Local - POC

This is a proof of concept for using Symfony Flex Recipes locally. This is not in any way feature complete or bug proof.

* Added support for merging yaml files - POC

## Usage
* In your custom package, create a `recipe` folder and add recipe configuration.
  * `manifest.json` file should be in following format, similar to but not exactly as described in [Symfony documentation](https://symfony.com/doc/current/setup/flex_private_recipes.html).
    ```json
    {
        "manifest": {
            "bundles": {
                "Acme\\PrivateBundle\\AcmePrivateBundle": [
                    "all"
                ]
            },
            "copy-from-recipe": {
                "config/": "%CONFIG_DIR%"
            },
            "yaml": {
                "config/security.yaml": "%CONFIG_DIR%/packages/security.yaml"
            }
        },
        "files": {
            "config/packages/acme_private.yaml": {
                "contents": [
                    "acme_private:",
                    "    encode: true",
                    ""
                ],
                "executable": false
            }
        },
        "ref": "7405f3af1312d1f9121afed4dddef636c6c7ff00"
    }
    ```
* Enable _Symfony Flex Local_ usage in `extra` section of `composer.json`
  ```json
  "extra": {
      "flex-local": true
  }
  ```
* Add dependency to _Symfony Flex Local_ in `composer.json`
  ```json
    "require": {
        "chamil/flex-local": "@dev"
    },
    "repositories": [
        {
            "type": "git",
            "url": "https://github.com/chamilwijesooriya/flex-local.git"
        }
    ]
  ```
* Install your custom package in the app 