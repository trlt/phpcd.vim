let s:save_cpo = &cpo
set cpo&vim

let g:phpcd_root = '/'
let g:phpcd_php_cli_executable = 'php'
let g:phpcd_autoload_path = 'vendor/autoload.php'
let g:phpcd_disable_modifier = 0

autocmd BufWritePost *.php call phpcd#UpdateIndex()
autocmd FileType php setlocal omnifunc=phpcd#CompletePHP
autocmd InsertLeave,CompleteDone *.php if pumvisible() == 0 | pclose | endif

if get(g:, 'phpcd_auto_restart', 0)
	autocmd FileType php autocmd BufEnter <buffer> call phpcd#EnterBufferWithAutoRestart()
endif

let &cpo = s:save_cpo
unlet s:save_cpo

" vim: foldmethod=marker:noexpandtab:ts=2:sts=2:sw=2
