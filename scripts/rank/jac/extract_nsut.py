#!/usr/bin/env python3
import sys, csv; sys.path.insert(0,'.')
from parse_matrix import *
SUB_OUT={'gender_neutral':'gender-neutral','girl':'girl','single_girl':'single-girl','pwd':'pwd','defense_cw':'defense-cw'}
from collections import Counter

# code (no asterisks) -> (full branch name, campus)  [mirrors JacCutoffImporter::NSUT_MAP]
NSUT = {
 'CSAI':('Computer Science & Engineering (AI)','Main (Dwarka)'),
 'CSE':('Computer Science & Engineering','Main (Dwarka)'),
 'CSDS':('Computer Science & Engineering (Data Science)','Main (Dwarka)'),
 'IT':('Information Technology','Main (Dwarka)'),
 'ITNS':('Information Technology (Network & Info Security)','Main (Dwarka)'),
 'MAC':('Mathematics & Computing','Main (Dwarka)'),
 'EVDT':('Electronics Engg (VLSI Design & Technology)','Main (Dwarka)'),
 'ECE':('Electronics & Communication Engineering','Main (Dwarka)'),
 'EE':('Electrical Engineering','Main (Dwarka)'),
 'ICE':('Instrumentation & Control Engineering','Main (Dwarka)'),
 'ME':('Mechanical Engineering','Main (Dwarka)'),
 'BT':('Bio-Technology','Main (Dwarka)'),
 'CSDA':('Computer Science & Engineering (Big Data Analytics)','East Campus'),
 'CIOT':('Computer Science & Engineering (IoT)','East Campus'),
 'ECAM':('Electronics & Comm Engg (AI & ML)','East Campus'),
 'MEEV':('Mechanical Engineering (Electric Vehicles)','West Campus'),
 'CE':('Civil Engineering','West Campus'),
 'GI':('Geoinformatics','West Campus'),
}
ROWCODE=re.compile(r'^([A-Za-z]+)(\*{0,2})$')

def rowlabel(row):
    for xc,x0,x1,yc,t in row:
        if x0<115:
            m=ROWCODE.match(t)
            if m and m.group(1).upper() in NSUT:
                return m.group(1).upper()
            if t.startswith('B.Arch'):
                return 'BARCH'
            return None  # leftmost non-code token -> not a data row
    return None

out=[]
for pg in pages('nsut.xml'):
    rows=cluster_rows(pg)
    cur=None
    for row in rows:
        h=header_of(row)
        if h: cur=h; continue
        if cur is None: continue
        lbl=rowlabel(row)
        if lbl is None or lbl=='BARCH': continue
        full,campus=NSUT[lbl]
        inst='NSUT '+campus
        got=assign(row,cur)
        for code,val in got.items():
            d=decode(code)
            if not d: continue
            cat,sub,reg=d
            out.append([inst,full,'1',reg,cat,SUB_OUT[sub],val])

with open('nsut_long.csv','w',newline='') as f:
    w=csv.writer(f); w.writerow(['institute','branch','round','region','category','sub_category','closing_rank'])
    w.writerows(out)

print("NSUT rows:",len(out))
print("by inst:",Counter(r[0] for r in out))
print("by region:",Counter(r[3] for r in out))
print("by sub:",Counter(r[5] for r in out))
def find(full,reg,cat,sub):
    for r in out:
        if r[1]==full and r[3]==reg and r[4]==cat and r[5]==sub: return r[6]
    return None
print("--- CSAI Delhi checks (CW alignment risk) ---")
print("GNGND exp 4133:", find('Computer Science & Engineering (AI)','delhi','general','gender_neutral'))
print("STGND exp 250860:", find('Computer Science & Engineering (AI)','delhi','st','gender_neutral'))
print("GNCWD exp 235458:", find('Computer Science & Engineering (AI)','delhi','general','defense_cw'))
print("OBCWD exp 27111:", find('Computer Science & Engineering (AI)','delhi','obc','defense_cw'))
print("SCCWD exp 287879:", find('Computer Science & Engineering (AI)','delhi','sc','defense_cw'))
print("STCWD exp 688799:", find('Computer Science & Engineering (AI)','delhi','st','defense_cw'))
print("GNPDD exp 59863:", find('Computer Science & Engineering (AI)','delhi','general','pwd'))
print("EWPDD exp 622555:", find('Computer Science & Engineering (AI)','delhi','ews','pwd'))
print("EWCWD exp blank:", find('Computer Science & Engineering (AI)','delhi','ews','defense_cw'))
