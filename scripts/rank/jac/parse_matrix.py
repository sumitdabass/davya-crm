#!/usr/bin/env python3
"""Parse JAC Delhi 2026 R1 category-cutoff matrix PDFs (DTU, NSUT) to long CSV.

Codes are 5 chars: [cat 2][sub 2][region 1].
  cat:  GN=general EW=ews OB=obc SC=sc ST=st
  sub:  GN=gender_neutral GL=girl SG=single_girl PD=pwd CW=defense_cw
  region: D=delhi O=outside_delhi
Blank cells = no admission. Values may carry "(VI)" priority annotations (ignored).
"""
import re, sys, csv, xml.etree.ElementTree as ET

CAT = {'GN':'general','EW':'ews','OB':'obc','SC':'sc','ST':'st'}
SUB = {'GN':'gender_neutral','GL':'girl','SG':'single_girl','PD':'pwd','CW':'defense_cw'}
REG = {'D':'delhi','O':'outside_delhi'}
CODE = re.compile(r'^[A-Z]{5}$')

def decode(code):
    c, s, r = code[0:2], code[2:4], code[4]
    if c in CAT and s in SUB and r in REG:
        return CAT[c], SUB[s], REG[r]
    return None

def pages(path):
    """Yield list of words [(xc, x0, x1, yc, text)] per page."""
    tree = ET.parse(path)
    for pg in tree.iter():
        if pg.tag.split('}')[-1] != 'page':
            continue
        ws = []
        for w in pg.iter():
            if w.tag.split('}')[-1] != 'word':
                continue
            x0, x1 = float(w.get('xMin')), float(w.get('xMax'))
            y0, y1 = float(w.get('yMin')), float(w.get('yMax'))
            t = (w.text or '').strip()
            if t:
                ws.append(((x0+x1)/2, x0, x1, (y0+y1)/2, t))
        yield ws

def cluster_rows(ws, tol=4.0):
    """Group words into rows by y center."""
    ws = sorted(ws, key=lambda r: (r[3], r[1]))
    rows, cur, cy = [], [], None
    for w in ws:
        if cy is None or abs(w[3]-cy) <= tol:
            cur.append(w); cy = w[3] if cy is None else (cy+w[3])/2
        else:
            rows.append(sorted(cur, key=lambda r: r[1])); cur=[w]; cy=w[3]
    if cur: rows.append(sorted(cur, key=lambda r: r[1]))
    return rows

def numval(t):
    m = re.match(r'^(\d{2,7})', t.replace(',',''))
    return int(m.group(1)) if m else None

def header_of(row):
    codes = {t:(xc) for xc,x0,x1,yc,t in row if CODE.match(t) and decode(t)}
    return codes if len(codes) >= 4 else None

def assign(row, header, max_dist=22):
    """Map numeric tokens in row to header columns by nearest x-center."""
    out = {}
    for xc,x0,x1,yc,t in row:
        v = numval(t)
        if v is None or v < 50:   # ignore S.No and tiny ints
            continue
        best, bd = None, 1e9
        for code, hx in header.items():
            d = abs(xc-hx)
            if d < bd: bd, best = d, code
        if best is not None and bd <= max_dist:
            out.setdefault(best, []).append((bd, v, xc))
    # keep nearest value per column
    return {c: min(v)[1] for c,v in out.items()}
