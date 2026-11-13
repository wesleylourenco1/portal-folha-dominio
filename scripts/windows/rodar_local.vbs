' Inicia o vigia da maquina da Dominio (agente; portal local so com PORTAL_LOCAL=1) sem abrir janela.
Set fso = CreateObject("Scripting.FileSystemObject")
pasta = fso.GetParentFolderName(WScript.ScriptFullName)
CreateObject("WScript.Shell").Run "powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File """ & pasta & "\rodar_local.ps1""", 0, False
