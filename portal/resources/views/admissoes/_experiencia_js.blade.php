<script>
  // Experiência + prorrogação: até {{ \App\Support\CamposAdmissao::EXPERIENCIA_MAX }} dias somados (CLT, art. 445)
  (function () {
    const max = {{ \App\Support\CamposAdmissao::EXPERIENCIA_MAX }};
    const exp = document.getElementById('experiencia_dias');
    const pro = document.getElementById('experiencia_prorrogacao_dias');
    if (!exp || !pro) return;
    const aviso = document.createElement('div');
    aviso.className = 'dica';
    aviso.style.color = 'var(--err)';
    pro.parentNode.appendChild(aviso);
    function conferir() {
      const total = (parseInt(exp.value) || 0) + (parseInt(pro.value) || 0);
      pro.max = Math.max(0, max - (parseInt(exp.value) || 0));
      const erro = total > max ? `A soma (${total} dias) passa do limite de ${max} dias.` : '';
      pro.setCustomValidity(erro);
      aviso.textContent = erro;
    }
    exp.addEventListener('input', conferir);
    pro.addEventListener('input', conferir);
    conferir();
  })();
</script>
