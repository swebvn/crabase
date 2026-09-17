#!/usr/bin/env python3
"""Real WebSocket commands, two subscribers, simulated Codex delta, reconnect. No model calls."""
import base64, json, os, socket, struct, subprocess, uuid, tempfile
import atexit, urllib.request, urllib.error
from pathlib import Path

class Client:
    def __init__(self, origin='http://127.0.0.1:8787', cookie=''):
        self.socket = socket.create_connection(('127.0.0.1',8788),timeout=5)
        self.sequence, self.buffer, self.events = 0, b'', []
        key = base64.b64encode(os.urandom(16)).decode()
        self.socket.sendall(f'GET / HTTP/1.1\r\nHost: 127.0.0.1:8788\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Version: 13\r\nSec-WebSocket-Key: {key}\r\nOrigin: {origin}\r\nCookie: {cookie}\r\n\r\n'.encode())
        while b'\r\n\r\n' not in self.buffer:
            chunk = self.socket.recv(4096)
            if not chunk: raise ConnectionError('Handshake rejected')
            self.buffer += chunk
        header,self.buffer = self.buffer.split(b'\r\n\r\n',1)
        assert header.split(b' ')[1] == b'101',header
    def read(self,count):
        while len(self.buffer)<count:
            chunk = self.socket.recv(4096)
            if not chunk: raise ConnectionError('Socket closed')
            self.buffer += chunk
        result,self.buffer = self.buffer[:count],self.buffer[count:]
        return result
    def send(self,message):
        data=json.dumps(message).encode(); size=len(data); mask=os.urandom(4)
        header=bytes([0x81,0x80|size]) if size<126 else bytes([0x81,0xfe])+struct.pack('!H',size)
        self.socket.sendall(header+mask+bytes(v^mask[i%4] for i,v in enumerate(data)))
    def receive(self):
        first,size=self.read(2)
        if first&15 == 8: raise ConnectionError('Socket closed')
        assert first&15 == 1 and first&128
        size &= 127
        if size==126: size=struct.unpack('!H',self.read(2))[0]
        elif size==127: size=struct.unpack('!Q',self.read(8))[0]
        return json.loads(self.read(size))
    def call(self,action,data=None,error=False):
        self.sequence+=1; self.send({'id':self.sequence,'action':action,'data':data or {}})
        while True:
            packet=self.receive()
            if packet.get('id')==self.sequence:
                assert ('error' in packet)==error,packet
                return packet.get('result',packet.get('error'))
            self.events.append(packet)
    def patch(self,field):
        while True:
            p=self.events.pop(0) if self.events else self.receive()
            if p.get('type')=='patch' and field in p:return p
    def close(self):self.socket.close()

if __name__=='__main__':
    server = Path(__file__).resolve().parents[1]
    accounts = json.loads(subprocess.check_output(['php','tests/auth-fixture.php'],cwd=server))
    atexit.register(lambda: subprocess.run(['php','tests/auth-fixture.php','cleanup',*[a['id'] for a in accounts]],cwd=server,check=True))
    cookies = []
    for account in accounts:
        request = urllib.request.Request('http://127.0.0.1:8787/auth/login', data=json.dumps(account).encode(), headers={'Content-Type':'application/json','Origin':'http://127.0.0.1:8787'})
        with urllib.request.urlopen(request) as response:
            cookie = response.headers['Set-Cookie']
            assert 'HttpOnly' in cookie and 'SameSite=Strict' in cookie
            cookies.append(cookie.split(';')[0])
    try:
        anonymous=Client(); anonymous.call('sync')
        raise AssertionError('Anonymous WebSocket accepted')
    except (ConnectionError,ConnectionResetError,BrokenPipeError): pass
    finally:
        if 'anonymous' in locals(): anonymous.close()
    try:
        urllib.request.urlopen('http://127.0.0.1:8787/files/anything/test.png')
        raise AssertionError('Anonymous files accepted')
    except urllib.error.HTTPError as error: assert error.code == 401
    try:
        rejected=Client('https://untrusted.example');rejected.call('sync')
        raise AssertionError('Untrusted origin accepted')
    except (ConnectionError,ConnectionResetError,BrokenPipeError):pass
    finally:
        if 'rejected' in locals():rejected.close()
    first,second=Client(cookie=cookies[0]),Client(cookie=cookies[1])
    second.call('usersList',error=True)
    second.call('health',error=True)
    health=first.call('health')
    assert health['checked_at'] and len(health['checks']) >= 6
    assert {'WebSocket worker','Codex app-server','Database','Agent queue','Terminal host','Runtime storage','Workspace storage'} <= {check['name'] for check in health['checks']}
    assert all(check['status'] in {'ok','warning','error','idle'} and check['detail'] for check in health['checks'])
    second.call('projectFolders',error=True)
    second.call('worktreeCreate',{'project_id':'invalid','branch':'feature/test'},error=True)
    second.call('project',{'path':'/'},error=True)
    # Disposable project: verify management permissions and deletion pushes to an open subscriber.
    with tempfile.TemporaryDirectory(prefix='crabase-delete-') as temp:
        project_id=uuid.uuid4().hex[:16]
        keep=Path(temp)/'keep.txt'; keep.write_text('keep')
        subprocess.run(['php','-r',"require 'vendor/autoload.php'; app\\service\\Store::run('INSERT INTO projects (id,name,path) VALUES (?,?,?)', [$argv[1],'Delete check',$argv[2]]);",project_id,temp],cwd=server,check=True)
        owned=first.call('create',{'project_id':project_id})['id']
        first.call('message',{'chat_id':owned,'body':'Delete fixture','mode':'note'})
        assert first.call('projectSharing',{'project_id':project_id}) == {'visibility':'private','members':[]}
        hidden=second.call('sync')['state']
        assert project_id not in [p['id'] for p in hidden['projects']]
        assert owned not in [c['id'] for c in hidden['chats']]
        second.call('sync',{'chat_id':owned},error=True)
        for action in ['projectWorkspace','projectFile','projectSave','projectDiff','projectContext','create','projectPin']:
            second.call(action,{'project_id':project_id,'path':'keep.txt','pinned':True},error=True)
        for action in ['message','archive','cancel','approval']:
            second.call(action,{'chat_id':owned,'body':'Denied','mode':'note'},error=True)
        request=urllib.request.Request(f'http://127.0.0.1:8787/files/{owned}/keep.txt',headers={'Cookie':cookies[1]})
        try:
            urllib.request.urlopen(request)
            raise AssertionError('Private artifact accepted')
        except urllib.error.HTTPError as error: assert error.code == 404
        second.call('projectSharingSave',{'project_id':project_id,'visibility':'public','members':[]},error=True)
        first.call('projectSharingSave',{'project_id':project_id,'visibility':'private','members':['missing']},error=True)
        first.call('projectSharingSave',{'project_id':project_id,'visibility':'private','members':[accounts[1]['id']]})
        assert second.call('sync',{'chat_id':owned})['thread']['messages'][0]['body']=='Delete fixture'
        # Child workspaces inherit both grants and revocation from the original project.
        child=uuid.uuid4().hex[:16]
        subprocess.run(['php','-r',"require 'vendor/autoload.php'; app\\service\\Store::db(); app\\model\\Project::query()->create(['id'=>$argv[1],'name'=>'Child','path'=>$argv[2], 'parent_id'=>$argv[3]]); app\\service\\Store::notify();",child,temp+'/child',project_id],cwd=server,check=True)
        child_chat=first.call('create',{'project_id':child})['id']
        assert second.call('sync',{'chat_id':child_chat})['thread']['chat']['project_id']==child
        second.events.clear()
        first.call('projectSharingSave',{'project_id':project_id,'visibility':'private','members':[]})
        revoked=second.patch('state')
        assert revoked['access_revoked']
        assert project_id not in [p['id'] for p in revoked['state']['projects']]
        assert child not in [p['id'] for p in revoked['state']['projects']]
        second.call('terminalOpen',error=True)
        second.call('sync',{'chat_id':child_chat},error=True)
        first.call('projectSharingSave',{'project_id':project_id,'visibility':'public','members':[]})
        assert second.call('sync',{'chat_id':child_chat})['thread']['chat']['project_id']==child
        second.call('projectSharingSave',{'project_id':project_id,'visibility':'private','members':[]},error=True)
        subprocess.run(['php','-r',"require 'vendor/autoload.php'; app\\service\\Store::db(); app\\model\\Event::query()->where('chat_id',$argv[1])->delete(); app\\model\\Chat::query()->whereKey($argv[1])->delete(); app\\model\\Project::query()->whereKey($argv[2])->delete(); app\\service\\Store::notify();",child_chat,child],cwd=server,check=True)
        second.call('sync',{'chat_id':owned})
        second.call('projectArchive',{'project_id':project_id,'archived':True},error=True)
        second.call('projectDelete',{'project_id':project_id},error=True)
        second.events.clear()
        second.call('projectPin',{'project_id':project_id,'pinned':True,'user_id':accounts[0]['id']})
        assert project_id in second.patch('state')['state']['pins']
        assert project_id not in first.call('sync')['state']['pins']
        second.close(); second=Client(cookie=cookies[1])
        assert project_id in second.call('sync')['state']['pins']
        second.call('projectPin',{'project_id':project_id,'pinned':False})
        assert project_id not in second.call('sync')['state']['pins']
        second.call('projectPin',{'project_id':project_id,'pinned':'true'},error=True)
        second.call('projectPin',{'project_id':'missing','pinned':True},error=True)
        second.call('projectPin',{'project_id':project_id,'pinned':True})
        first.call('projectArchive',{'project_id':project_id,'archived':True})
        assert next(p for p in second.call('sync')['state']['projects'] if p['id']==project_id)['archived']==1
        first.call('projectArchive',{'project_id':project_id,'archived':False})
        second.call('sync',{'chat_id':owned}); second.events.clear()
        first.call('projectDelete',{'project_id':project_id})
        pushed=second.patch('state')['state']
        assert all(p['id']!=project_id for p in pushed['projects'])
        assert all(c['id']!=owned for c in pushed['chats'])
        assert project_id not in pushed['pins']
        assert keep.read_text()=='keep'
        first.call('sync',{'chat_id':owned},error=True)
    state=first.call('sync')['state']; users={u['name']:u['id'] for u in state['users']}; assert state['projects'] and state['chats']
    workspace=first.call('projectWorkspace', {'project_id':state['projects'][0]['id']})
    assert set(workspace)=={'paths','ignored','git','branch','changes'}
    folders=first.call('projectFolders')
    assert folders['parent'] is None and all(not f['name'].startswith('.') for f in folders['folders'])
    first.call('projectFolders', {'path':'/does-not-exist-crabase'}, error=True)
    first.call('unknown',error=True)
    first.call('create',{'project_id':'missing'},error=True)
    first.call('create',{'title':' '},error=True)
    first.call('project',{'name':'Missing','path':'/does-not-exist-crabase'},error=True)
    chat=first.call('create',{'title':'WebSocket check '+uuid.uuid4().hex[:6]})['id'];folder_chat=None
    try:
        assert first.call('sync',{'chat_id':chat})['thread']['chat']['project_id'] is None
        second.call('sync',{'chat_id':chat});first.events.clear();second.events.clear()
        first.call('rename',{'chat_id':chat,'title':'Renamed WebSocket check'})
        pushed=second.patch('state')['state']
        assert next(c for c in pushed['chats'] if c['id']==chat)['title']=='Renamed WebSocket check'
        first.call('rename',{'chat_id':chat,'title':' '},error=True)
        first.call('message',{'chat_id':chat,'body':' ','mode':'note'},error=True)
        first.call('message',{'chat_id':chat,'body':'check','mode':'invalid'},error=True)
        body='Live note '+uuid.uuid4().hex
        first.call('message',{'chat_id':chat,'body':body,'mode':'note','user_id':users['user1']})
        update=second.patch('messages');assert update['chat_id']==chat and update['messages'][0]['body']==body
        assert update['messages'][0]['author']==accounts[0]['name'], 'Client-supplied identity was trusted'
        message_id=update['messages'][0]['id']
        # Use the same Store append as Codex, targeting only our disposable test note.
        subprocess.run(['php','-r',"require 'vendor/autoload.php'; app\\service\\Store::run('UPDATE messages SET body=body || ? WHERE id=?', [' streamed', (int)$argv[1]]);",str(message_id)],cwd=Path(__file__).resolve().parents[1],check=True)
        delta=second.patch('append');assert delta['append']==[{'id':message_id,'delta':' streamed'}]
        assert 'messages' not in delta and 'state' not in delta,'Delta resent history or workspace'
        second.close();second=Client(cookie=cookies[1]);restored=second.call('sync',{'chat_id':chat})['thread']
        assert restored['messages'][0]['body']==body+' streamed' and restored['chat']['thread_id'] is None
        second.call('message',{'chat_id':chat,'body':'Reply from second test user','mode':'note','user_id':users['user2']})
        both=first.call('sync',{'chat_id':chat})['thread']['messages']
        assert [m['author'] for m in both]==[a['name'] for a in accounts]
        incremental=first.call('sync',{'chat_id':chat,'after':message_id})['thread']['messages']
        assert incremental and all(m['id']>=message_id for m in incremental)
        assert incremental[-1]['body']=='Reply from second test user'
        first.call('sync',{'chat_id':chat,'after':'invalid'},error=True)
        # Publishing pushes the file list to subscribers and survives reconnect/sync.
        with tempfile.TemporaryDirectory() as temp:
            source=Path(temp)/'report.csv'; source.write_text('name,value\ntest,1\n')
            server=Path(__file__).resolve().parents[1]
            published=json.loads(subprocess.check_output(['php','bin/publish-artifact.php',chat,str(source)],cwd=server))
            artifact_root=Path(subprocess.check_output(['php','-r',"require 'vendor/autoload.php'; echo app\\service\\Artifacts::root();"],cwd=server).decode())
            try:
                files=second.patch('artifacts')['artifacts']
                assert len(files)==1 and files[0]['url']==published['url']
                assert files[0]['size']==source.stat().st_size
                assert first.call('sync',{'chat_id':chat})['thread']['artifacts']==files
            finally:
                (artifact_root/chat/published['name']).unlink()
                (artifact_root/chat).rmdir()
        first.call('archive',{'chat_id':chat,'archived':True})
        first.call('message',{'chat_id':chat,'body':'must not save','mode':'note'},error=True)
        first.call('archive',{'chat_id':chat,'archived':False})
        assert second.call('sync',{'chat_id':chat})['thread']['chat']['archived']==0
        folder_chat=first.call('create',{'title':'Folder thread check','project_id':state['projects'][0]['id']})['id']
        assert first.call('sync',{'chat_id':folder_chat})['thread']['chat']['project_id']==state['projects'][0]['id']
    finally:
        first.call('archive',{'chat_id':chat,'archived':True})
        if folder_chat:first.call('archive',{'chat_id':folder_chat,'archived':True})
        first.close();second.close()
    print('PASS: WebSocket commands, origin/validation, two-client push, text-only deltas, reconnect persistence, archive/restore, standalone chats and folder threads.')
