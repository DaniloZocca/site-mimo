import React, { useState, useEffect } from 'react';
import { PRINT_FARM_TICKERS } from '../data/mockProducts';
import { Radio, Sparkles } from 'lucide-react';

export const LiveFarmTicker = () => {
  const [currentIndex, setCurrentIndex] = useState(0);

  useEffect(() => {
    const interval = setInterval(() => {
      setCurrentIndex((prev) => (prev + 1) % PRINT_FARM_TICKERS.length);
    }, 4500);
    return () => clearInterval(interval);
  }, []);

  return (
    <div className="bg-mimoo-dark text-white py-2 px-4 border-y-2 border-mimoo-dark flex items-center justify-between text-xs font-fredoka overflow-hidden">
      <div className="max-w-7xl mx-auto w-full flex items-center justify-between gap-4">
        
        {/* Left Live Badge */}
        <div className="flex items-center gap-2 flex-shrink-0">
          <span className="relative flex h-2.5 w-2.5">
            <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
            <span className="relative inline-flex rounded-full h-2.5 w-2.5 bg-emerald-500"></span>
          </span>
          <span className="text-[11px] font-bold text-mimoo-yellow uppercase tracking-wider hidden sm:inline">
            Fazenda 3D ao Vivo:
          </span>
        </div>

        {/* Rolling Message */}
        <div className="flex-1 text-center truncate px-2 text-slate-200 animate-in fade-in duration-300 key={currentIndex}">
          {PRINT_FARM_TICKERS[currentIndex]}
        </div>

        {/* Right Status */}
        <div className="hidden md:flex items-center gap-2 text-slate-400 text-[11px] flex-shrink-0">
          <Sparkles className="w-3.5 h-3.5 text-mimoo-pink" />
          <span>FDM 0.12mm Ultra HD</span>
        </div>

      </div>
    </div>
  );
};
