# get NEXT_VERSION from prep-release script
VERSION = $(shell cat .next-version)
REPOTOP = $(shell pwd)
UNAME = $(shell uname)
PKG = plugin-updater-github
PKG_DIR = $(PKG)-$(VERSION)
PKG_FILE = $(PKG_DIR).zip
PKG_FILE_PATH = build/$(PKG_DIR)

all: target-list

target-list:
	@echo "Targets:"
	@echo "  make clean"
	@echo "  make build"
	@echo "  make test"
.PHONY: target-list

clean:
	rm -rf build
.PHONY: clean

build:
	mkdir -p build/$(PKG_DIR)
	cp -r src/* build/$(PKG_DIR)
	cp composer.json build/$(PKG_DIR)
	cp README.md build/$(PKG_DIR)
	cp LICENSE build/$(PKG_DIR)
	-cp CHANGELOG.md build/$(PKG_DIR)
	-cd build && find . -name '.DS_Store' -type f -delete && cd $(REPOTOP)
	cd build && zip -r $(PKG_FILE) $(PKG_DIR)
.PHONY: build
