app_name    := integration_immich
build_dir   := $(CURDIR)/build
release_dir := $(build_dir)/release
source_dir  := $(build_dir)/source/$(app_name)

# Files/dirs to include in the release ZIP
appfiles := appinfo \
            css \
            img \
            js \
            l10n \
            lib \
            templates \
            vendor \
            CHANGELOG.md \
            COPYING \
            README.md \
            composer.json \
            composer.lock

.PHONY: all clean build release

all: build

## Install PHP dependencies (production, no dev)
composer:
	composer install --no-dev --prefer-dist --optimize-autoloader

## Install JS dependencies
npm-install:
	npm ci

## Build production JS bundles
build: npm-install
	npm run build

## Build JS + create release ZIP
release: composer build
	@echo "--- Assembling release package ---"
	rm -rf "$(source_dir)"
	mkdir -p "$(source_dir)"
	cp -r $(appfiles) "$(source_dir)/"
	mkdir -p "$(release_dir)"
	cd "$(build_dir)/source" && find "$(app_name)/js" -name "*.map" -delete
	cd "$(build_dir)/source" && zip -r "$(release_dir)/$(app_name).zip" "$(app_name)"
	@echo ""
	@echo "✅  Release ZIP: $(release_dir)/$(app_name).zip"

## Remove build artifacts
clean:
	rm -rf "$(build_dir)"
	rm -rf node_modules
	rm -rf vendor
