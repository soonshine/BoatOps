<style>
.inquiry-layout { overflow-wrap: anywhere; }
.inquiry-layout nav { flex-wrap: wrap; align-items: center; }
.inquiry-page,
.inquiry-page * { box-sizing: border-box; }
.inquiry-page { max-width: 72rem; margin: 0 auto; }
.inquiry-page { overflow-wrap: anywhere; }
.inquiry-page .card { border-radius: .5rem; }
.inquiry-form-grid,
.inquiry-summary-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0 1rem;
}
.inquiry-form-grid > *,
.inquiry-summary-grid > * { min-width: 0; }
.inquiry-form-grid .wide,
.inquiry-summary-grid .wide { grid-column: 1 / -1; }
.inquiry-page input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]),
.inquiry-page select,
.inquiry-page textarea {
    display: block;
    width: 100%;
    margin-top: .35rem;
    padding: .55rem;
}
.inquiry-page textarea { min-height: 5.5rem; resize: vertical; }
.inquiry-help { color: #555; font-size: .92rem; }
.inquiry-warning { background: #fff5d6; border-left: .3rem solid #b36b00; }
.inquiry-complete { background: #eaf7ed; border-left: .3rem solid #237a36; }
.inquiry-fact { margin: .5rem 0; }
.inquiry-fact strong { display: block; }
@media (max-width: 640px) {
    .inquiry-form-grid,
    .inquiry-summary-grid { grid-template-columns: minmax(0, 1fr); }
    .inquiry-form-grid .wide,
    .inquiry-summary-grid .wide { grid-column: auto; }
}
@media (max-width: 480px) {
    .inquiry-layout { margin: 1rem; }
    .inquiry-layout nav form { width: 100%; margin: 0; }
}
</style>
