.PHONY: setup lint phpcs

lint:
	phplint application/ library/
phpcs:
	phpcs
setup:
	mkdir -p _libraries &&\
	git clone --depth 1 -b snapshot/nightly https://github.com/Icinga/icinga-php-library.git _libraries/ipl &&\
	git clone --depth 1 -b snapshot/nightly https://github.com/Icinga/icinga-php-thirdparty.git _libraries/vendor &&\
	git clone --depth 1 https://github.com/NETWAYS/icingaweb2-module-perfdatagraphs.git _libraries/perfdatagraphs &&\
	git clone --depth 1 https://github.com/Icinga/icingaweb2.git _icingaweb2
