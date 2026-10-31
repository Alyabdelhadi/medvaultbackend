<style>
.mv-slip{background:#fff;border:1.5px dashed var(--line-strong);border-radius:18px;padding:26px 28px 22px;color:var(--ink);max-width:420px}
.mv-slip-brand{display:flex;align-items:center;gap:12px;font-size:20px}
.mv-slip-mark{width:42px;height:42px;border-radius:11px;background:#fff;border:1px solid var(--line);display:flex;align-items:center;justify-content:center;overflow:hidden;padding:3px}
.mv-slip-mark img{width:100%;height:100%;object-fit:contain}
.mv-slip-welcome{font-size:19px;font-weight:500;margin:20px 0 6px}
.mv-slip-ph{color:#a9b3c4}
.mv-slip-text{color:var(--ink-2);font-size:14px;line-height:1.6;margin:0 0 16px}
.mv-slip-text b{color:var(--ink)}
.mv-slip-cred{display:flex;justify-content:space-between;align-items:center;background:#f1f4f8;border-radius:10px;padding:12px 16px;margin-bottom:10px;font-size:14px;color:var(--ink-2)}
.mv-slip-cred b{font-size:19px;color:var(--ink);letter-spacing:.02em}
.mv-slip-mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
.mv-slip-app{display:flex;gap:16px;align-items:center;border-top:1px solid var(--line);margin-top:16px;padding-top:18px}
.mv-slip-app .mv-qr{width:112px;height:112px;flex:none}
.mv-slip-app div{display:flex;flex-direction:column;gap:4px;font-size:14px;color:var(--ink-2)}
.mv-slip-app b{color:var(--ink);font-size:16px}
.mv-slip-app small{font-size:13px;margin-top:6px}
.mv-slip-foot{font-size:12px;color:var(--muted);margin:16px 0 0;line-height:1.5}
@media print{
  @page{margin:12mm}
  body *{visibility:hidden}
  .mv-slip,.mv-slip *{visibility:visible}
  .mv-slip{position:absolute;left:0;top:0;border:none;max-width:110mm;-webkit-print-color-adjust:exact;print-color-adjust:exact}
}
</style>
