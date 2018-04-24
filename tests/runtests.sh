#! /usr/bin/env bash
#
#Script to run all unit-tests of phpcd
#
#Script dependencies:
#bash-version 4.4 or above
#vimunit installed parallel to phpcds install dir
#for vimunit see dsummersl/vimunit on github

declare -a TESTSTORUN

set -eu

function help(){
	echo "USAGE: runtests.sh[ options][ list of tests]"
	echo ""
	echo "-e    vim-executeable e.g. vim nvim etc"
	echo "-h|--help|-?    prints this help"
} 

function setVim(){
	if [[ -n ${VIM+x} ]]; then
		#script called with -e
			VIM=$(which $VIM)
	else
		#first look for nvim
		#nvim was used in earlier version, try to keep backward compatible
		VIM=$(which nvim)
		if [ 0 -ne $? ]; then
			#nvim not found, look for vim.nox
			VIM=$(which vim.nox)
			if [ 0 -ne $? ]; then
				VIM=$(which vim)
			fi
		fi
	fi

	if [ -z $VIM ] || [ ! -x $VIM ]; then
		echo -e "\033[31mNo vim executable found or given!\033[0m"
		exit 1
	fi
}
while [ 0 -lt $# ]; do
	case "$1" in
	'-e' )
		shift
		if [ 0 -eq $# ]; then
			echo -e "\033[31m-e musst be followed by a vim like program!\033[0m"
			exit 4
		fi
		VIM="$1"
      ;;
	'--help'|'-h'|'-?')
      help
	  exit 0
      ;;
	*)
		if [[ "$1" =~ "^-" ]]; then
			echo -e "\033[31mUnknow parameter \"$1\"\033[0m"
			help
			exit 3
		fi
		TESTSTORUN+=("$1")
  esac
  shift
done

setVim

DIR=$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )

# vimunit in the plugin root's parent dir (think of ~/.vim/bundle)
VU="$(dirname $(dirname $DIR))/vimunit/vutest.sh"

if [ ! -f "$VU" ]; then
	echo -e "\033[31mCould not run tests. Vimunit executeable not found at: '$VU'\033[0m"
	exit 1
fi

if [[ ${TESTSTORUN[@]} ]]; then
	#run tests given as param
	cnt=${#TESTSTORUN[*]}
	for ((i = 0; i < $cnt; i++)); do
		$VU "-e $VIM -u $DIR/vimrc" ${TESTSTORUN[$i]}
	done
else
	#run all tests
	for f in "$DIR/"*.vim; do
		$VU "-e $VIM -u $DIR/vimrc" $f
	done
fi
