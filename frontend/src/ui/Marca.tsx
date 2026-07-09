/** Lockup do logo, reproduzindo a hierarquia do deck: hexágono, nome, linha, subtítulo. */
export function Marca({ compacto = false }: { compacto?: boolean }) {
  return (
    <div className="flex items-center gap-3">
      <div className="hex bg-rust flex h-9 w-8 items-center justify-center">
        <span className="text-sand-light text-sm font-black">F</span>
      </div>
      <div className="leading-none">
        <div className={`text-ink font-black tracking-tight ${compacto ? 'text-lg' : 'text-3xl'}`}>
          FERTWAYS
        </div>
        {!compacto && (
          <div className="text-rust eyebrow mt-1">— The Next Colony —</div>
        )}
      </div>
    </div>
  )
}
