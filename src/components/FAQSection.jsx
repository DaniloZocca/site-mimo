import React, { useState } from 'react';
import { FAQS } from '../data/mockProducts';
import { ChevronDown, HelpCircle, Sparkles } from 'lucide-react';

export const FAQSection = () => {
  const [openIdx, setOpenIdx] = useState(0);

  return (
    <section className="py-14 sm:py-20 bg-slate-50 border-b-2 border-mimoo-dark/10">
      <div className="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-8">
        
        <div className="text-center space-y-3">
          <div className="inline-flex items-center gap-1.5 bg-mimoo-purple-tint text-mimoo-purple font-fredoka text-xs font-bold px-3 py-1 rounded-full border border-purple-200">
            <HelpCircle className="w-3.5 h-3.5" />
            <span>Tire Suas Dúvidas</span>
          </div>

          <h2 className="font-fredoka font-bold text-3xl sm:text-4xl text-mimoo-dark">
            Perguntas Frequentes sobre a Impressão 3D
          </h2>

          <p className="font-nunito text-slate-500 text-sm sm:text-base">
            Tudo o que você precisa saber sobre tempos de produção, filamentos ecológicos e cuidados.
          </p>
        </div>

        <div className="space-y-3">
          {FAQS.map((faq, index) => {
            const isOpen = openIdx === index;
            return (
              <div 
                key={index}
                className="card-chunky bg-white overflow-hidden transition-all"
              >
                <button
                  onClick={() => setOpenIdx(isOpen ? null : index)}
                  className="w-full p-4 sm:p-5 text-left flex items-center justify-between gap-4 font-fredoka font-bold text-sm sm:text-base text-mimoo-dark hover:text-mimoo-purple transition-colors"
                >
                  <span className="flex items-center gap-2.5">
                    <span className="w-6 h-6 rounded-full bg-mimoo-purple-tint text-mimoo-purple flex items-center justify-center text-xs flex-shrink-0">
                      {index + 1}
                    </span>
                    {faq.question}
                  </span>
                  <ChevronDown className={`w-5 h-5 text-slate-400 transition-transform duration-200 flex-shrink-0 ${
                    isOpen ? 'rotate-180 text-mimoo-purple' : ''
                  }`} />
                </button>

                {isOpen && (
                  <div className="px-5 pb-5 pt-1 text-xs sm:text-sm text-slate-600 font-nunito leading-relaxed border-t border-slate-100 animate-in fade-in duration-200">
                    {faq.answer}
                  </div>
                )}
              </div>
            );
          })}
        </div>

      </div>
    </section>
  );
};
