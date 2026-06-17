#!/usr/bin/env python3
import sys, csv; sys.path.insert(0,'.')
from parse_matrix import *
SUB_OUT={'gender_neutral':'gender-neutral','girl':'girl','single_girl':'single-girl','pwd':'pwd','defense_cw':'defense-cw'}

DTU_BRANCH = {
 1:'Computer Science and Engineering',
 2:'Computer Science and Engineering (Data Science & Analytics)',
 3:'Information Technology',
 4:'Information Technology (Cyber Security)',
 5:'Software Engineering',
 6:'Mathematics and Computing',
 7:'Electronics and Communication Engineering',
 8:'Electronics Engineering (VLSI Design and Technology)',
 9:'Electrical Engineering',
 10:'Mechanical Engineering',
 11:'Mechanical and Automation Engineering',
 12:'Mechanical Engineering with specialization in Automotive Engineering',
 13:'Engineering Physics',
 14:'Chemical Engineering',
 15:'Civil Engineering',
 16:'Production & Industrial Engineering',
 17:'Environmental Engineering',
 18:'Bio-Technology',
}

def sno(row):
    for xc,x0,x1,yc,t in row:
        if x0 < 120 and re.fullmatch(r'\d{1,2}', t):
            n=int(t)
            if 1<=n<=18: return n
    return None

out=[]
for pg in pages('dtu.xml'):
    rows = cluster_rows(pg)
    cur=None
    for row in rows:
        h=header_of(row)
        if h: cur=h; continue
        if cur is None: continue
        n=sno(row)
        if n is None: continue
        got=assign(row,cur)
        if not got: continue
        for code,val in got.items():
            d=decode(code)
            if not d: continue
            cat,sub,reg=d
            out.append(['DTU',DTU_BRANCH[n],'1',reg,cat,SUB_OUT[sub],val])

# write
with open('dtu_long.csv','w',newline='') as f:
    w=csv.writer(f); w.writerow(['institute','branch','round','region','category','sub_category','closing_rank'])
    w.writerows(out)

print("DTU rows:",len(out))
from collections import Counter
print("by region:",Counter(r[3] for r in out))
print("by sub:",Counter(r[5] for r in out))
print("by cat:",Counter(r[4] for r in out))
print("branches:",len(set(r[1] for r in out)))
# spot checks
def find(b,reg,cat,sub):
    for r in out:
        if r[1]==b and r[3]==reg and r[4]==cat and r[5]==sub: return r[6]
    return None
print("CHECK CSE delhi gen/gn (exp 9170):", find('Computer Science and Engineering','delhi','general','gender_neutral'))
print("CHECK CSE outside gen/gn (exp 3430):", find('Computer Science and Engineering','outside_delhi','general','gender_neutral'))
print("CHECK CSE delhi gen/defense_cw (exp 897590):", find('Computer Science and Engineering','delhi','general','defense_cw'))
print("CHECK Bio delhi st/girl (exp blank? row18 STGLD):", find('Bio-Technology','delhi','st','girl'))
print("CHECK Civil delhi general/single_girl (exp 114000):", find('Civil Engineering','delhi','general','single_girl'))
