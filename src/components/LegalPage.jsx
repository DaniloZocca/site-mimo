import React, { useEffect } from 'react';
import { ArrowRight } from 'lucide-react';
import { useShop } from '../context/ShopContext';
import { LEGAL_PAGES } from '../content/legal';
import { LOJA, LOJA_CAMPOS } from '../config/loja';

// M-41: Política de Privacidade e Termos de Uso. Mesmo padrão visual do HowItWorks (seção branca com bolinhas,
// selo amarelo, título Fredoka e cards chunky). Os textos ficam em src/content/legal.js.

const MissingField = ({ field }) => (
  <span className="font-bold text-amber-800 bg-mimoo-yellow-light border border-amber-400 rounded px-1">
    [a preencher: {LOJA_CAMPOS[field] || field}]
  </span>
);

// Troca **negrito** e {{campo}} (dado da loja) pelo elemento certo; campo vazio vira o aviso "[a preencher]".
const RichText = ({ text }) =>
  text.split(/(\*\*[^*]+\*\*|\{\{\w+\}\})/g).map((part, index) => {
    if (part.startsWith('**') && part.endsWith('**')) {
      return <strong key={index} className="text-mimoo-dark"><RichText text={part.slice(2, -2)} /></strong>;
    }
    const field = part.match(/^\{\{(\w+)\}\}$/);
    if (field) {
      const value = LOJA[field[1]];
      return value ? <React.Fragment key={index}>{value}</React.Fragment> : <MissingField key={index} field={field[1]} />;
    }
    return <React.Fragment key={index}>{part}</React.Fragment>;
  });

export const LegalPage = ({ page }) => {
  const { setActiveTab } = useShop();
  const content = LEGAL_PAGES[page];
  const otherPage = page === 'privacy' ? 'terms' : 'privacy';

  // Link com seção (ex.: ?pagina=termos#trocas) rola até ela.
  useEffect(() => {
    const id = window.location.hash.slice(1);
    if (id) document.getElementById(id)?.scrollIntoView();
  }, [page]);

  const goTo = (tab) => {
    setActiveTab(tab);
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };

  return (
    <section className="py-14 sm:py-20 bg-white border-b-2 border-mimoo-dark/10 relative overflow-hidden">

      {/* Background Dots */}
      <div className="absolute inset-0 bg-dot-pattern opacity-40 pointer-events-none" />

      <div className="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">

        {/* Section Header */}
        <div className="text-center max-w-2xl mx-auto mb-10 sm:mb-12 space-y-3">
          <div className="inline-flex items-center gap-2 bg-mimoo-yellow text-mimoo-dark font-fredoka text-xs font-bold px-3 py-1 rounded-full border-2 border-mimoo-dark shadow-chunky-sm">
            <span>{content.badge}</span>
          </div>

          <h2 className="font-fredoka font-bold text-3xl sm:text-4xl text-mimoo-dark tracking-tight">
            {content.title}
          </h2>

          <p className="font-nunito text-slate-600 text-sm sm:text-base">
            <RichText text={content.intro} />
          </p>

          <p className="font-nunito text-xs text-slate-500">
            Última atualização: <RichText text="{{atualizadoEm}}" />
          </p>
        </div>

        {/* Sections */}
        <div className="space-y-6">
          {content.sections.map((section) => (
            <article key={section.id} id={section.id} className="card-chunky p-6 scroll-mt-6">
              <h3 className="font-fredoka font-bold text-lg text-mimoo-dark leading-snug mb-3">
                {section.title}
              </h3>

              {section.items && (
                <ul className="list-disc pl-5 space-y-2 mb-3 last:mb-0 font-nunito text-xs sm:text-sm text-slate-600 leading-relaxed">
                  {section.items.map((item) => (
                    <li key={item}><RichText text={item} /></li>
                  ))}
                </ul>
              )}

              {section.paragraphs && (
                <div className="space-y-2">
                  {section.paragraphs.map((paragraph) => (
                    <p key={paragraph} className="font-nunito text-xs sm:text-sm text-slate-600 leading-relaxed">
                      <RichText text={paragraph} />
                    </p>
                  ))}
                </div>
              )}
            </article>
          ))}
        </div>

        {/* Navegação entre as páginas */}
        <div className="mt-10 flex flex-col sm:flex-row items-center justify-center gap-4">
          <button
            onClick={() => goTo(otherPage)}
            className="btn-chunky-white px-6 py-3 text-sm flex items-center gap-2"
          >
            <span>{otherPage === 'terms' ? '📜 Ler os Termos de Uso' : '🔒 Ler a Política de Privacidade'}</span>
          </button>
          <button
            onClick={() => goTo('catalog')}
            className="btn-chunky-yellow px-6 py-3 text-sm flex items-center gap-2"
          >
            <span>Voltar para a Loja</span>
            <ArrowRight className="w-4 h-4" />
          </button>
        </div>

      </div>

    </section>
  );
};

export default LegalPage;
