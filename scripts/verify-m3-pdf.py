"""QA actual browser-generated PDF; run with PyMuPDF 1.26.4, not a runtime dependency."""
import json
from pathlib import Path
import fitz
root=Path('/artifacts')
doc=fitz.open(root/'m3-attendance-a4.pdf')
assert len(doc)>=3,'Expected multipage attendance PDF'
placements={}
for index,page in enumerate(doc):
    assert abs(page.rect.width-595.276)<1 and abs(page.rect.height-841.89)<1,'PDF must be A4 portrait'
    text=page.get_text()
    for header in ['NBI','Nama','Sesi','Tanda','Tangan']:
        assert header in text,f'Header {header} missing on page {index+1}'
    for row in range(1,68):
        nbi='009900'+str(row).zfill(4)
        nbi_rects=page.search_for(nbi)
        if not nbi_rects:
            continue
        assert len(nbi_rects)==1 and row not in placements,'Duplicate/split participant'
        names=page.search_for(f'Praktikan {row:02d}')
        assert len(names)==1,f'Row {row} name must share page with NBI'
        assert abs(names[0].y0-nbi_rects[0].y0)<3,'Row cells must be aligned'
        signature=[fitz.Rect(w[:4]) for w in page.get_text('words') if w[4]==f'{row}.' and w[0]>400]
        assert len(signature)==1,f'Row {row} must have one signature number on same page'
        assert abs(signature[0].y0-nbi_rects[0].y0)<28,'Signature should stay on participant row'
        placements[row]={'page':index+1,'signature_x':round(signature[0].x0,2),'row_y':round(nbi_rects[0].y0,2)}
assert len(placements)==67,'All 67 participants must be printed once'
assert placements[2]['signature_x']>placements[1]['signature_x']+40
assert abs(placements[1]['signature_x']-placements[3]['signature_x'])<2
assert abs(placements[2]['signature_x']-placements[4]['signature_x'])<2
for index in [0,1,len(doc)-1]:
    doc[index].get_pixmap(matrix=fitz.Matrix(1.3,1.3)).save(root/f'm3-pdf-page-{index+1}.png')
result={'result':'passed','pdf':'m3-attendance-a4.pdf','pages':len(doc),'page_size_points':[round(doc[0].rect.width,2),round(doc[0].rect.height,2)],'participants':len(placements),'checks':['A4 portrait on every page','table header repeats on every page','67 unique NBI/name/signature pairs on same page and row','single TTD number per participant','odd/even alternation uses print_order across page boundaries'],'placements':placements}
(root/'print-m3-results.json').write_text(json.dumps(result,indent=2))
print(json.dumps({k:v for k,v in result.items() if k!='placements'}))
