# Changelog

## [1.8.3](https://github.com/rubicon/diviops/compare/mcp-server-v1.8.2...mcp-server-v1.8.3) (2026-08-18)


### Bug Fixes

* **dist:** rebuild the plugin zips from source and gate them against drift ([#234](https://github.com/rubicon/diviops/issues/234)) ([3433a1c](https://github.com/rubicon/diviops/commit/3433a1c97284141da602bee2ec6c42d2c2312dcb)), closes [#229](https://github.com/rubicon/diviops/issues/229)
* **dist:** stop tracking the plugin zips and verify the builder instead ([#239](https://github.com/rubicon/diviops/issues/239)) ([70ea48c](https://github.com/rubicon/diviops/commit/70ea48cc587def1d5ee5e0ed6ecebb4bc5b22bc5)), closes [#238](https://github.com/rubicon/diviops/issues/238)

## [1.8.2](https://github.com/rubicon/diviops/compare/mcp-server-v1.8.1...mcp-server-v1.8.2) (2026-08-18)


### Bug Fixes

* **npm:** stop declaring bins the package does not ship, and gate it ([#223](https://github.com/rubicon/diviops/issues/223)) ([d855aac](https://github.com/rubicon/diviops/commit/d855aac86de1c0107a001c85b045c081debb5614))

## [1.8.1](https://github.com/rubicon/diviops/compare/mcp-server-v1.8.0...mcp-server-v1.8.1) (2026-08-17)


### Bug Fixes

* **module_update:** serialize canonically and merge object attrs instead of replacing ([#207](https://github.com/rubicon/diviops/issues/207)) ([8671c2b](https://github.com/rubicon/diviops/commit/8671c2bd8f32106f3e0e15a2767a4ac96b91394a))

## [1.8.0](https://github.com/rubicon/diviops/compare/mcp-server-v1.7.0...mcp-server-v1.8.0) (2026-08-15)


### Features

* **#37:** extend TSF SEO adapter with OG/Twitter title and description fields ([#179](https://github.com/rubicon/diviops/issues/179)) ([a7fbe59](https://github.com/rubicon/diviops/commit/a7fbe594d1ed7e3e7b129705817ea683de88180d)), closes [#37](https://github.com/rubicon/diviops/issues/37)
* **server:** publish as @rubicontv/diviops-mcp with a smoke-gated workflow ([#164](https://github.com/rubicon/diviops/issues/164)) ([2997e59](https://github.com/rubicon/diviops/commit/2997e59e5293e9e3c4cef6b71774024fd83ade13))


### Bug Fixes

* **preset:** give preset_reassign snapshots, a write guard, and an honest envelope ([#196](https://github.com/rubicon/diviops/issues/196)) ([079bf20](https://github.com/rubicon/diviops/commit/079bf201bd69c705e6e6a913240d9aca9950b760))
* **server:** recover wp-cli JSON payloads from a polluted stdout stream ([#178](https://github.com/rubicon/diviops/issues/178)) ([b802f98](https://github.com/rubicon/diviops/commit/b802f98fa030e0aad6e76064ed79598287b63ebf)), closes [#167](https://github.com/rubicon/diviops/issues/167)
* **server:** scf_field_group_get reports a JSON-parse failure instead of not_found ([#176](https://github.com/rubicon/diviops/issues/176)) ([d03ae50](https://github.com/rubicon/diviops/commit/d03ae50d7e97d8c943feda13e1a73ce52b8a6c0e)), closes [#168](https://github.com/rubicon/diviops/issues/168)

## [1.7.0](https://github.com/rubicon/diviops/compare/mcp-server-v1.6.2...mcp-server-v1.7.0) (2026-08-14)


### Features

* **media:** admin-configurable capability gate for SVG uploads ([#158](https://github.com/rubicon/diviops/issues/158)) ([5639a88](https://github.com/rubicon/diviops/commit/5639a88130191d8892d732cf28243025d15fc9cc)), closes [#73](https://github.com/rubicon/diviops/issues/73)
* **server:** thread the MCP request AbortSignal through every tool wrapper ([#155](https://github.com/rubicon/diviops/issues/155)) ([f6c0620](https://github.com/rubicon/diviops/commit/f6c0620321d40fec6c0b27d84c2b4126df7074cd))

## [1.6.2](https://github.com/rubicon/diviops/compare/mcp-server-v1.6.1...mcp-server-v1.6.2) (2026-08-14)


### Bug Fixes

* **server:** ship LICENSE in the published npm tarball ([#150](https://github.com/rubicon/diviops/issues/150)) ([6065787](https://github.com/rubicon/diviops/commit/6065787b206f70c0c772a68c565fbba39e35beeb)), closes [#149](https://github.com/rubicon/diviops/issues/149)

## [1.6.1](https://github.com/rubicon/diviops/compare/mcp-server-v1.6.0...mcp-server-v1.6.1) (2026-08-01)


### Bug Fixes

* **#41, #128:** diviops-server builds and starts from a clean checkout ([#129](https://github.com/rubicon/diviops/issues/129)) ([c6d72d0](https://github.com/rubicon/diviops/commit/c6d72d081d6b685c13f2af795e6da55f7c45e121))

## [1.6.0](https://github.com/rubicon/diviops/compare/mcp-server-v1.5.38...mcp-server-v1.6.0) (2026-07-31)


### Features

* **#116:** rebuild diviops-server skill-doc regen tooling ([#117](https://github.com/rubicon/diviops/issues/117)) ([c51b788](https://github.com/rubicon/diviops/commit/c51b7882571d10aaa382a2a9deef8ffc83d158a7))


### Bug Fixes

* **#120:** reach every module namespace in schema_get_module ([#121](https://github.com/rubicon/diviops/issues/121)) ([b6269e9](https://github.com/rubicon/diviops/commit/b6269e91fa9ca2eac84ca1b78544b5344ff265be))
* **#123:** stop the dashboard claiming a WP-CLI capability it cannot see ([#124](https://github.com/rubicon/diviops/issues/124)) ([4a77df8](https://github.com/rubicon/diviops/commit/4a77df8312d0d3d5364801eff6160925b11aacc6))
