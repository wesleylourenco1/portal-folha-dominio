' Inicia o vigia do agente (manter_ligado.ps1) sem abrir janela.
Set fso = CreateObject("Scripting.FileSystemObject")
pasta = fso.GetParentFolderName(WScript.ScriptFullName)
CreateObject("WScript.Shell").Run "powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File """ & pasta & "\manter_ligado.ps1""", 0, False
