ng = open('ng.txt', 'w')
ng.write('$spams = array(')
flag = False
for line in open('spams.txt', 'r'):
	if line[:4] == '[NG]':
		if flag == False:
			ng.write(line.replace('[NG] ', "'").replace('\n', "',"))
			flag = True
		else:
			ng.write(line.replace('[NG] ', ",'").replace('\n', "'"))
ng.write(');')
ng.close()
