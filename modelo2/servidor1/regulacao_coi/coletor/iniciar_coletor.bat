@echo off
title Coletor - Regulacao COI (Modelo 2)
cd /d "%~dp0"
if not exist config.ini (
  echo Copie config.ini.exemplo para config.ini e preencha antes de iniciar.
  pause
  exit /b 1
)
if not exist .venv (
  echo Criando ambiente virtual...
  py -3.12 -m venv .venv 2>nul || python -m venv .venv
  .venv\Scripts\pip install -r requirements.txt
)
.venv\Scripts\python coletor.py %*
pause
