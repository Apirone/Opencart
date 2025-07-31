TAG := $(shell test -d .git && git tag --sort=taggerdate | tail -1 || echo dev)
PWD := $(shell pwd)

all: v2 v3 v4

v2: .v2
v3: .v3
v4: .v4

.v%:
	rm -rf '$(PWD)/build'

	mkdir -p "$(PWD)/build/upload/system/library"
	cp -r "$(PWD)/apirone_api" "$(PWD)/build/upload/system/library" 

	$(eval VER := $(subst .v,,$@))
	$(eval ARCPATH := "$(PWD)/apirone-crypto-payments.oc$(VER).$(TAG).ocmod.zip")

	for D in `ls $(PWD)/$(VER).x.x`; do \
		cp `test -d $(PWD)/$(VER).x.x/$$D && echo \-r` $(PWD)/$(VER).x.x/$$D $(PWD)/build/upload/; \
	done

	cd "${PWD}/build/`test $(VER) -gt 3 && echo upload`" && zip -r "$(ARCPATH)" ./*

clean:
	rm -rf '$(PWD)/build'

.PHONY: v2 v3 v4 clean
