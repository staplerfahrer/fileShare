rmdir /s/q _deploy
set src=fileShare
set dst=_deploy\fileShare
robocopy %src% %dst% /MIR /NFL /NDL /NP /NJH /NJS
if %errorlevel% geq 8 exit %errorlevel%
set src=
set dst=